<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Media\Exceptions\MediaTemporaryFileException;
use App\Services\Media\MediaTempFiles;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsMedia;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the actual act of publishing a `PostGroup` to every channel it was
 * scheduled to — `PublishPostJob` is a one-line wrapper around
 * `publishGroup()` (see that class), everything that decides WHAT
 * happens for a given provider outcome lives here.
 *
 * Runs with NO `OrganizationContext` set — this is a queued job, and
 * `App\Listeners\ClearOrganizationContext` clears it around every job
 * boundary (Task 4). Every query against a `BelongsToOrganization`
 * model in this class therefore goes through `withoutGlobalScope('organization')`
 * BY NAME, never the blanket `withoutGlobalScopes()` — see
 * `TokenRefreshService::dueForRefresh()` (Task 16) for the identical,
 * already-reviewed pattern this class copies. `Model::save()`/`fresh()`
 * need no such treatment: both build their query via
 * `newModelQuery()`/`newQueryWithoutScopes()`, which never apply global
 * scopes in the first place (verified directly against the framework
 * source, not assumed — see `BelongsToOrganization`'s docblock).
 *
 * ============================================================
 * Idempotency — every path that could publish a segment twice
 * ============================================================
 *
 * 1. Re-running `publishGroup()` for an already-`PUBLISHED` (or already
 *    `ERROR`, terminal-for-this-run) group: the very first thing this
 *    method does is take the group row `lockForUpdate()` inside a short
 *    transaction and re-check `state === QUEUE`. Anything else exits
 *    immediately with no further action — no provider is ever touched.
 *    This is what makes calling `publishGroup()` twice in a row (Task
 *    30's brief, test 2) a true no-op the second time.
 *
 * 2. Re-running mid-chain, per SEGMENT: `publishChannel()` below only
 *    ever builds a payload chain out of a channel's posts that satisfy
 *    BOTH `release_id IS NULL` AND `state === QUEUE`. A segment that
 *    already carries a `release_id` — set the instant a provider
 *    confirmed it went out, see `markPublished()` — is never included in
 *    a chain sent to `post()` again, on any future run. The database
 *    backs this up independently: `posts_release_id_immutable` (Task 18)
 *    makes a second UPDATE that tried to touch a set `release_id`
 *    physically fail (`Post::RELEASE_ID_IMMUTABLE_SQLSTATE`) rather than
 *    silently overwrite it — this class simply never attempts that
 *    UPDATE in the first place, so the trigger is defence-in-depth here,
 *    not the primary guard.
 *
 * 3. Re-running mid-chain, per CHANNEL: if every post in a channel's
 *    chain already satisfies `release_id IS NOT NULL` (or is a
 *    terminally-failed `ERROR` segment with no `release_id` — see below),
 *    `publishChannel()` returns immediately without calling
 *    `ProviderRegistry::get()` or `post()` at all. A fully-published
 *    channel costs nothing on retry.
 *
 * 4. Concurrent execution of the SAME group: the primary defence is
 *    `PublishPostJob`'s `ShouldBeUnique` (keyed on the group id), which
 *    stops a second instance of the job from ever being enqueued while
 *    one is in flight — the same reasoning `RefreshIntegrationTokenJob`
 *    documents for `Integration`. `PostGroupService`'s reschedule paths
 *    deliberately force-release that lock before redispatching (see that
 *    class's docblock), so — unlike the token-refresh case — TWO
 *    instances of this job for the same group genuinely can run at once;
 *    this class adds a second, narrower layer on top that has to hold
 *    even then: `publishChannel()` re-locks (`lockForUpdate()`) exactly
 *    the post rows it is about to publish, inside the SAME transaction
 *    that calls the provider and persists the result, so a second caller
 *    racing for the identical channel blocks until the first either
 *    commits (and the second then sees `release_id` already set and
 *    skips) or rolls back (nothing was sent, safe to retry). Deliberately
 *    scoped to one channel's pending rows, not the whole group and not
 *    the whole run — see the next section for why holding one lock
 *    across the ENTIRE run (all channels, one HTTP call each) was
 *    rejected. `queue_version` (Task 31, compared in `lockGroupIfQueued()`)
 *    is a THIRD, independent layer, not a substitute for either of the
 *    two above: it stops a job from a SUPERSEDED schedule generation from
 *    ever reaching `publishChannel()` at all (a reschedule case), whereas
 *    the two layers above stop two genuinely-concurrent SAME-generation
 *    runs from double-publishing a segment. All three hold simultaneously.
 *
 *    This re-lock's `WHERE` MUST filter on `state = QUEUE` as well as
 *    `release_id IS NULL` — post-review fix, not a simplification to
 *    ever reverse. `release_id IS NULL` alone is not sufficient: a
 *    concurrent `abandonGroup()` run (`PublishPostJob::failed()`, once
 *    retries are exhausted) can mark a channel's still-unpublished rows
 *    `ERROR` — `release_id` stays null, only `state` changes — between
 *    this method's unlocked pre-check and this transaction acquiring the
 *    lock. Without the `state` predicate, this method would still pick
 *    those `ERROR` rows up, call the provider, and `markPublished()`
 *    would then attempt `ERROR -> PUBLISHED`, a transition
 *    `PostStateMachine` rejects — throwing INSIDE this transaction AFTER
 *    the network call already went out, rolling `release_id` back to
 *    null while the message is live. Not an immediate duplicate today,
 *    because the pending filter this re-lock feeds already requires
 *    `state === QUEUE` — but Task 31's retry endpoint moves individual
 *    posts `ERROR -> QUEUE`, and that is the next task: the moment it
 *    ships, a customer clicking "retry" on exactly this row republishes
 *    a segment already on the network, with no record it was ever sent.
 *    The `state` filter here is what makes this method simply skip such
 *    a row instead, on both counts, today and after Task 31.
 *
 * ==========================================================
 * Why the group-level lock is short, not held for the whole run
 * ==========================================================
 *
 * The brief's contract says "take the group `lockForUpdate()` inside a
 * transaction; if the state is not QUEUE, exit without acting". It does
 * NOT say the entire publish run (every channel's provider HTTP call)
 * must happen inside that same transaction, and deliberately: a
 * transaction spanning multiple slow network calls is exactly what turns
 * a worker crash (killed process, OOM, a deploy) mid-run into a
 * duplicate-publish bug. If segment A's `post()` call actually reached a
 * real Telegram channel — a message is now live — and the process dies
 * before the wrapping transaction commits, Postgres rolls the whole
 * transaction back: `release_id` reverts to NULL in the database while
 * it is very much NOT null on the remote network, and the very next
 * retry re-sends segment A, producing exactly the double-post this whole
 * task exists to prevent. Committing per-channel (see `publishChannel()`)
 * means a crash can only ever lose progress on the ONE channel that was
 * in flight at the moment of the crash — not the whole group, and never
 * a channel whose provider call already returned successfully.
 *
 * Final-review correction (Critical 3): "bounds the damage to one
 * channel" is the honest claim, and an earlier revision of this docblock
 * overstated it as CLOSING the crash case. It does not. A process killed
 * mid-`post()` never throws `ProviderPartialFailureException` — nothing
 * runs in that process again at all — so segments this channel had
 * already put on the network are rolled back locally with no record they
 * were sent, and the next attempt re-sends them. Two deployment
 * settings, not this class, keep that from becoming routine:
 * `REDIS_QUEUE_RETRY_AFTER` (300) must stay well ABOVE
 * `supervisor-publish`'s `timeout` (90) so a slow run is never
 * redelivered to a second worker WHILE the first is still sending — they
 * were equal, which is the reproducible duplicate-publish path written
 * out in `docs/DEPLOYMENT.md`, "Queue timeouts" — and every provider
 * HTTP call carries an explicit timeout (`BoundsHttpTimeouts`) so one
 * hung host cannot stretch a run past the worker timeout on its own.
 * The residual case (a deploy or OOM kill landing inside a live
 * `post()`) is accepted and documented there; no network here offers a
 * client-supplied idempotency key that would let this class close it.
 *
 * Point 4's `ShouldBeUnique` claim carries the same caveat, recorded
 * here rather than fixed in this batch: `PublishPostJob`'s unique lock
 * is acquired at DISPATCH time with a fixed 3600s TTL, independent of
 * `->delay()`, so for anything scheduled more than an hour out it has
 * already expired by the time the job runs and guards nothing. The row
 * locks below, `queue_version`, and (since this batch) `publishChannel()`
 * and `finalizeGroupState()`'s in-transaction re-checks are what
 * actually carry the guarantee in the common case; a dynamic
 * `uniqueFor()` is deferred.
 *
 * ==========================================
 * Resuming a partially published chain
 * ==========================================
 *
 * `ProviderPartialFailureException::$published` carries a `PostResultDto`
 * for every leading chain index that DID make it out before the
 * failure, keyed by the same 0-based index `publishChannel()` used when
 * it built the payload chain (i.e. relative to the channel's PENDING
 * segments for this call, not the channel's absolute `order`). Every
 * index present in `$published` is persisted via `markPublished()` —
 * `release_id`/`release_url`/`published_at` set, state moved to
 * `PUBLISHED` — exactly as if `post()` had returned it directly; every
 * index NOT present is left completely untouched (still `QUEUE`, no
 * `release_id`), and this run reports the channel as needing a retry
 * rather than marking those segments `ERROR` (the underlying reason for
 * the failure — transient, rate-limited, or something else — is not
 * distinguishable from a bare `ProviderPartialFailureException`, so this
 * class treats it exactly like a plain `ProviderTransientException`: an
 * unbounded number of retries within the job's own `$tries`, never a
 * permanent failure invented on its own authority).
 *
 * On the next call to `publishGroup()` for this group (a fresh job
 * attempt after `release()`, or a second manual call), `publishChannel()`
 * recomputes `$pending` for this channel — now starting after the
 * newly-published prefix — and separately looks up the LAST published
 * segment in the whole chain by `order` (which, after a partial failure,
 * is exactly the last one `markPublished()` touched) to use as
 * `$anchor`. `PostPayloadDto::withReplyToReleaseId($anchor)` is applied
 * to the resumed chain's first element ONLY — see that DTO's docblock
 * for why a provider that cannot honour this must throw
 * `ProviderPermanentException` rather than publish a duplicate,
 * detached root. `PublishPostJobTest`'s resumption test proves this by
 * asserting `FakeProvider::rootReleaseIds()` stays at exactly one entry
 * across both calls.
 */
class PublishService
{
    /**
     * How far `publish_date` may sit in the future before
     * `lockGroupIfQueued()` treats a job as "not due yet" and exits
     * without acting, rather than "close enough, publish now". See that
     * method's docblock — this is the fix for a rescheduled post
     * publishing at its OLD time (review finding 1): a small allowance
     * for normal queue/dispatch latency around the exact instant, not a
     * window a caller could exploit to jump the queue.
     */
    private const int PUBLISH_DATE_GRACE_SECONDS = 5;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly PostStateMachine $stateMachine,
        private readonly MediaTempFiles $mediaFiles,
    ) {}

    /**
     * @param  int|null  $queueVersion  Task 31: the `post_groups.queue_version`
     *                                  this call was dispatched for — see
     *                                  `lockGroupIfQueued()`'s docblock. Null
     *                                  means "no opinion, act regardless of
     *                                  the group's current version" — used
     *                                  by every caller other than
     *                                  `PublishPostJob` (which always passes
     *                                  a real value).
     *
     * @throws PublishRetryNeededException when at least one channel needs
     *                                     to be attempted again later —
     *                                     caught only by `PublishPostJob`.
     */
    public function publishGroup(string $postGroupId, ?int $queueVersion = null): void
    {
        $group = $this->lockGroupIfQueued($postGroupId, $queueVersion);

        if ($group === null) {
            return;
        }

        $chains = $this->loadChains($group);

        $needsRetry = false;
        $retryAfterSeconds = null;

        foreach ($chains as $integrationId => $posts) {
            [$channelNeedsRetry, $channelRetryAfterSeconds] = $this->publishChannel(
                $group->id,
                (string) $integrationId,
                $posts,
                $queueVersion,
            );

            if (! $channelNeedsRetry) {
                continue;
            }

            $needsRetry = true;

            if ($channelRetryAfterSeconds !== null) {
                $retryAfterSeconds = $retryAfterSeconds === null
                    ? $channelRetryAfterSeconds
                    : min($retryAfterSeconds, $channelRetryAfterSeconds);
            }
        }

        if ($needsRetry) {
            throw new PublishRetryNeededException($retryAfterSeconds);
        }

        $this->finalizeGroupState($group->id, $queueVersion);
    }

    /**
     * The other half of "a job that fires against a stale schedule must
     * never publish" (review finding 1): everything still `QUEUE` in
     * `$postGroupId` moves to `ERROR` with `$reason`, and the group is
     * finalized. Called from `PublishPostJob::failed()` once retries are
     * exhausted (see that class) — without this, a group that never
     * manages to publish sits at `QUEUE` forever with no error recorded
     * anywhere, which the calendar/API render identically to "still
     * scheduled, nothing wrong yet" (review finding 3).
     *
     * A no-op if the group is no longer `QUEUE` by the time this runs —
     * e.g. a run that was mid-flight when the job's LAST attempt failed
     * already published or errored some/all channels and finalized the
     * group itself; nothing here should second-guess that outcome.
     */
    public function abandonGroup(string $postGroupId, string $reason, ?int $queueVersion = null): void
    {
        DB::transaction(function () use ($postGroupId, $reason, $queueVersion): void {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
                return;
            }

            // Task 31: same reasoning as lockGroupIfQueued()'s queue_version
            // check below — a stale job's OWN retries running out must not
            // be allowed to error a group that has since been superseded by
            // a new schedule generation, even in the residual case where
            // isDueNow()'s due-date grace (checked next) would not by
            // itself have caught it.
            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                return;
            }

            // Same due-date grace as lockGroupIfQueued() — post-review
            // fix (finding 2, second defect). Without this, a group
            // dragged further into the future while a now-dead job for
            // its OLD schedule is still mid-retry gets marked ERROR the
            // moment that dead job exhausts and this method runs, even
            // though nothing has actually gone wrong yet — it just isn't
            // due. The redispatched job for the NEW date then finds
            // ERROR (not QUEUE) and no-ops on the state check above,
            // just like the original swallowed-redispatch bug: a post
            // that should still publish, silently doesn't. See
            // isDueNow()'s docblock for why this predicate must stay a
            // single shared source of truth between the two callers.
            if (! $this->isDueNow($group)) {
                return;
            }

            // lockForUpdate() — post-review fix (finding 2, first
            // defect). Without it, this SELECT could read a snapshot
            // taken just BEFORE a concurrent, still-in-flight
            // publishGroup() run commits a segment's release_id, and the
            // subsequent markAllError() below (a plain by-primary-key
            // UPDATE, no state predicate) would then overwrite that
            // freshly-PUBLISHED row with ERROR — no duplicate publish,
            // but the product would show a post that DID go out as
            // failed. Locking here forces this query to wait for any
            // such concurrent transaction to finish, then re-read the
            // qualifying rows fresh — exactly the same reasoning
            // publishChannel() already applies to its own pending-rows
            // read.
            $stillPending = Post::withoutGlobalScope('organization')
                ->where('post_group_id', $group->id)
                ->whereNull('release_id')
                ->where('state', PostState::QUEUE->value)
                ->lockForUpdate()
                ->get();

            $this->markAllError($stillPending, $reason);

            // Already inside this transaction's group lock — finalizeGroupState()
            // re-takes it (a no-op for a lock this transaction already holds)
            // and re-checks the same two predicates this method just checked.
            $this->finalizeGroupState($group->id, $queueVersion);
        });
    }

    /**
     * Locks and returns the group iff it is currently `QUEUE` AND due —
     * `publish_date` is not materially in the future (see
     * `PUBLISH_DATE_GRACE_SECONDS`); returns null (and does nothing else)
     * for anything else. `withoutGlobalScope('organization')` because
     * this runs with no `OrganizationContext` — see class docblock.
     * Deliberately its own short transaction, not the one wrapping the
     * whole run — see the class docblock's "why the group-level lock is
     * short" section.
     *
     * `$queueVersion` (Task 31) is compared to `$group->queue_version`
     * INSIDE this same `lockForUpdate()` transaction, not before it and
     * not by the caller beforehand — that placement is the entire point.
     * `PostGroupService::dispatchPublishIfQueued()` bumps this column
     * every single time it (re)dispatches `PublishPostJob` for a group,
     * and hands the freshly-bumped value to that specific job instance. A
     * job holding an OLDER value is, by construction, one whose schedule
     * generation has since been superseded — most commonly by a
     * reschedule, but the identical mechanism also covers "two instances
     * of this job somehow both reach the queue for the same group" (the
     * `ShouldBeUnique` lock's fixed 3600s TTL is independent of
     * `->delay()`, see `dispatchPublishIfQueued()`'s docblock — a real
     * race, not merely a hypothetical). Because the comparison happens
     * under the SAME row lock as the state check, and against the value
     * physically stored on the row at that exact instant, a stale job
     * cannot slip past it even if it wins the race to acquire the lock
     * first: whichever job runs first simply sees whatever `queue_version`
     * happens to be committed at that moment, and only a job whose own
     * version matches it exactly is allowed through. This closes a gap
     * the due-date check below does not: a group can be rescheduled to a
     * new date that has ALSO already passed by the time the stale job
     * fires (`isDueNow()` only rejects a date materially in the FUTURE),
     * in which case the due-date check alone would let it through.
     *
     * The due-date check is what makes a STALE job — one dispatched for
     * an old `publish_date` that `PostGroupService::updateDate()`/
     * `update()` has since moved into the future — harmless regardless
     * of whether a fresh job was ever successfully dispatched for the
     * new date (review finding 1: `ShouldBeUnique`'s lock can very
     * plausibly have already expired between the original dispatch and
     * the reschedule, so a redispatch is not guaranteed to happen, but a
     * stale job picking the group up early must never be able to publish
     * it regardless). A job that returns here because its group isn't
     * due yet does nothing further — no retry, no error — since either a
     * fresh job is on the way for the correct time, or (the one residual
     * gap this does not close) none was dispatched at all and nothing
     * will publish this group until something else touches it. That gap
     * is a missed-publish failure mode, not a duplicate-publish one, and
     * is called out in this task's report.
     */
    private function lockGroupIfQueued(string $postGroupId, ?int $queueVersion): ?PostGroup
    {
        return DB::transaction(function () use ($postGroupId, $queueVersion): ?PostGroup {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
                return null;
            }

            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                return null;
            }

            if (! $this->isDueNow($group)) {
                return null;
            }

            return $group;
        });
    }

    /**
     * True iff `$group->publish_date` is not materially in the future —
     * see `PUBLISH_DATE_GRACE_SECONDS`. A single shared predicate
     * between `lockGroupIfQueued()` (should this run publish anything at
     * all right now?) and `abandonGroup()` (should retries-exhausted be
     * allowed to settle this group to ERROR right now?) — post-review
     * fix, finding 2's second defect: an earlier revision only applied
     * this check in `lockGroupIfQueued()`, so `abandonGroup()` could mark
     * a group `ERROR` purely because a stale, already-superseded job's
     * retries ran out, even though the group had since been rescheduled
     * further into the future and was never actually due yet. Keeping
     * this as one method both callers share is deliberate: the two
     * checks answering a subtly different question ("act now?" vs.
     * "settle now?") from independently-maintained copies of the same
     * arithmetic is exactly how they would eventually drift apart.
     */
    private function isDueNow(PostGroup $group): bool
    {
        if ($group->publish_date === null) {
            return true;
        }

        return ! $group->publish_date->isAfter(now()->addSeconds(self::PUBLISH_DATE_GRACE_SECONDS));
    }

    /**
     * Every `Post` belonging to $group, eager-loaded with its media,
     * grouped by `integration_id` and ordered by `order` within each
     * group (the query's own `orderBy` — `groupBy()` on a Collection
     * preserves each item's original relative order within its bucket).
     *
     * `orderBy('id')` as a tiebreaker, added post-review: `order` is
     * assigned PER CHAIN by `PostGroupService::replaceChains()`, so every
     * channel's first segment is `order = 0` and a multi-channel group's
     * rows are full of ties. Without a tiebreaker the ORDER in which
     * channels are attempted is whatever Postgres happens to return —
     * which is not a correctness problem (channels are independent, and
     * each one's own segments are still strictly ordered, since `order`
     * is unique within a chain), but it is unstable: it makes a failing
     * multi-channel run hard to reproduce, and it silently decides which
     * channel is the one in flight when a concurrent edit lands. Post ids
     * are ULIDs, so this resolves ties by creation order — the order the
     * customer's own payload listed the channels in.
     *
     * @return SupportCollection<string, Collection<int, Post>>
     */
    private function loadChains(PostGroup $group): SupportCollection
    {
        /** @var Collection<int, Post> $posts */
        $posts = Post::withoutGlobalScope('organization')
            ->where('post_group_id', $group->id)
            ->with(['media' => fn ($query) => $query->withoutGlobalScope('organization')])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        /** @var SupportCollection<string, Collection<int, Post>> $grouped */
        $grouped = $posts->groupBy('integration_id');

        return $grouped;
    }

    /**
     * Publishes exactly one channel's still-pending segments, if any.
     * Returns `[needsRetry, retryAfterSeconds]` — never throws for a
     * provider failure, since a failing channel must never stop the
     * group's other channels from being attempted (brief point 7).
     *
     * @param  Collection<int, Post>  $posts  This channel's full chain, in order (published and unpublished) — a snapshot taken BEFORE the transaction below, used only to derive which ids are candidates; never trusted for anything that decides what gets sent to the provider (see the anchor note below).
     * @param  int|null  $queueVersion  The schedule generation this run belongs to, threaded down from `publishGroup()` — re-checked inside the transaction below, immediately before the provider call. See that check's own comment (final-review Critical 2).
     * @return array{0: bool, 1: ?int}
     */
    private function publishChannel(string $postGroupId, string $integrationId, Collection $posts, ?int $queueVersion = null): array
    {
        $pending = $posts
            ->filter(fn (Post $post): bool => $post->release_id === null && $post->state === PostState::QUEUE)
            ->values();

        if ($pending->isEmpty()) {
            // Either every segment already published, or every
            // remaining segment already failed permanently (ERROR, no
            // release_id) in an earlier attempt this same retry cycle —
            // either way, nothing left for this channel to do.
            return [false, null];
        }

        // Media is prepared BEFORE the transaction below opens, and the
        // temp files are discarded after it closes — final-review batch
        // 2, Critical 3. Two reasons it cannot go inside:
        //
        //  - `SupportsMedia::uploadMedia()` is one HTTP call per
        //    attachment, each bounded at 45s (`BoundsHttpTimeouts`), so a
        //    four-image segment could hold this channel's row locks for
        //    three minutes — well past `supervisor-publish`'s 90s worker
        //    timeout, which is precisely the shape batch 1 spent its
        //    Critical 3 on.
        //  - Copying a file out of object storage is I/O with no bearing
        //    on any row this transaction protects.
        //
        // What is deliberately NOT relaxed by doing the work early:
        // nothing here publishes anything. A pre-upload for a run that
        // then loses the lock, or whose generation has been superseded,
        // leaves an unreferenced attachment on the network (Mastodon
        // expires unused media, Bluesky garbage-collects unreferenced
        // blobs, and Slack's uploadMedia() stops before the share step
        // that would make a file visible) — never a visible post. The
        // decision to publish is still made entirely inside the
        // transaction, under the same locks and the same re-checks as
        // before.
        //
        // $preparationFailure defers a media failure INTO the transaction
        // rather than handling it here: it is rethrown at the top of the
        // provider try/catch below, so a missing file or a failed
        // pre-upload settles through exactly the same permanent /
        // transient / rate-limit machinery a failed `post()` does, under
        // the row locks that make marking those rows safe.
        // An unlocked pre-check, purely to avoid paying for uploads this
        // run has already lost the right to make: a superseded generation
        // or a redelivered job would otherwise pre-upload every
        // attachment and only then discover, inside the transaction, that
        // it must not publish. Deliberately NOT a guard — it decides
        // nothing. Every authoritative check still happens under the row
        // locks below, in the same order and with the same predicates;
        // this can only ever cause work to be skipped, never performed.
        if (! $this->stillPublishable($postGroupId, $queueVersion)) {
            return [false, null];
        }

        [$integration, $provider] = $this->channelProvider($integrationId);

        $preparedMedia = [];
        $temporaryFiles = [];
        $preparationFailure = null;

        if ($integration !== null && $provider !== null) {
            try {
                [$preparedMedia, $temporaryFiles] = $this->prepareMedia($provider, $integration, $pending);
            } catch (ProviderException|MediaTemporaryFileException $e) {
                $preparationFailure = $e;
            }
        }

        try {
            return $this->publishLockedChannel(
                $postGroupId,
                $integrationId,
                $pending,
                $queueVersion,
                $preparedMedia,
                $preparationFailure,
            );
        } finally {
            $this->mediaFiles->discard($temporaryFiles);
        }
    }

    /**
     * The transactional half of publishChannel() — everything that
     * decides whether this channel publishes, and records what happened.
     * Split out only so the media preparation above can sit outside the
     * transaction while every lock, re-check and outcome mapping below
     * stays exactly where (and in the order) it was.
     *
     * @param  Collection<int, Post>  $pending
     * @param  array<string, list<MediaDto>>  $preparedMedia  Publish-ready DTOs (local path, pre-upload handle where the provider needs one), keyed by post id.
     * @param  \Throwable|null  $preparationFailure  A media failure to raise inside the provider try/catch below, so it settles like any other provider failure.
     * @return array{0: bool, 1: ?int}
     */
    private function publishLockedChannel(
        string $postGroupId,
        string $integrationId,
        Collection $pending,
        ?int $queueVersion,
        array $preparedMedia,
        ?\Throwable $preparationFailure,
    ): array {
        return DB::transaction(function () use ($postGroupId, $integrationId, $pending, $queueVersion, $preparedMedia, $preparationFailure): array {
            // Re-lock exactly the rows about to be published, inside the
            // SAME transaction as the provider call and its persistence
            // — see the class docblock's idempotency section, point 4.
            $lockedIds = Post::withoutGlobalScope('organization')
                ->whereIn('id', $pending->pluck('id'))
                ->whereNull('release_id')
                ->where('state', PostState::QUEUE->value)
                ->lockForUpdate()
                ->pluck('id');

            if ($lockedIds->isEmpty()) {
                // A concurrent run already finished this channel between
                // this method's initial (unlocked) check and this
                // transaction acquiring the lock.
                return [false, null];
            }

            $pending = $pending->filter(fn (Post $post): bool => $lockedIds->contains($post->id))->values();

            // Re-read the GROUP, inside this transaction, after the row
            // locks above have resolved — final-review fix, Critical 2,
            // reproduced with two forked processes against real Postgres
            // (`PublishRaceTest`). Before this, `queue_version` and
            // `publish_date` were read exactly ONCE per run, in
            // `lockGroupIfQueued()`, whose lock is committed immediately;
            // nothing between that transaction and each channel's
            // provider call looked at them again. A calendar drag
            // (`PostGroupService::updateDate()`) landing while an earlier
            // channel's provider call was in flight therefore changed
            // nothing this channel checks: it touches `post_groups` only,
            // so this channel's rows still satisfy
            // `release_id IS NULL AND state = QUEUE`, and the run went on
            // to publish them AT THE OLD TIME — observed in the review's
            // run as a group dragged from 07:22 to 08:22 whose channel B
            // still went out at 07:22. Irrevocable: the message is live,
            // and `guardEditable()` 409s every later edit.
            //
            // A plain read, deliberately NOT `lockForUpdate()`: a writer
            // that could change this row (`PostGroupService::update()`,
            // `updateDate()`) takes the GROUP lock first and is THEN
            // blocked on `guardEditable()`'s post-row locks — the very
            // locks this transaction is holding. Locking the group here,
            // under those held post locks, is therefore not merely
            // "against the group -> posts convention": it is a direct
            // deadlock cycle with a writer already waiting on us while
            // holding what we would be asking for.
            //
            // What makes the PLAIN read sufficient — and this is the
            // subtle part, so read it before changing anything nearby.
            // It is NOT that a concurrent writer must have committed or
            // not started: a writer blocked in `guardEditable()` HAS
            // started, has NOT committed, and is invisible to this
            // SELECT, which will happily read the pre-drag
            // `queue_version`. Safety comes from the other side of that
            // wait: because that writer is blocked on THESE post rows, it
            // cannot commit its bump until this transaction ends, and
            // when its wait resolves it re-reads and sees the
            // `release_id` this transaction just committed — so it 409s
            // instead of superseding a publish that already went out.
            // Either this channel sees the new version and aborts, or the
            // writer sees the release and is refused; never both.
            //
            // That rests on an invariant this file cannot enforce alone,
            // stated here and in `guardEditable()`'s docblock:
            //
            //   EVERY path that bumps `queue_version` or moves
            //   `publish_date` on a QUEUE group MUST first take that
            //   group's pending post rows FOR UPDATE (i.e. go through
            //   `guardEditable()`).
            //
            // It holds today — `update()` and `updateDate()` both call
            // `guardEditable()`, `retry()` only accepts a group already
            // in ERROR (nothing is in flight for it), and
            // `MissedPostsService` never bumps. A new bump path that
            // skips those row locks would slip past this read and
            // reopen Critical 2 exactly as it was reproduced in
            // `PublishRaceTest`, with no test necessarily going red.
            //
            // Aborting is "no retry, no error": the superseding
            // generation has its own job, and this run has no standing to
            // mark anything failed on its behalf. `finalizeGroupState()`
            // applies the same two predicates and will likewise decline
            // to settle the group.
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
                return [false, null];
            }

            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                return [false, null];
            }

            if (! $this->isDueNow($group)) {
                return [false, null];
            }

            /** @var Integration|null $integration */
            $integration = Integration::withoutGlobalScope('organization')->find($integrationId);

            if ($integration === null) {
                // Defensive only: posts.integration_id is a restricted-
                // on-delete foreign key, so a live Post row referencing a
                // gone Integration should not be reachable in practice.
                $this->markAllError($pending, 'The connected channel no longer exists.');

                return [false, null];
            }

            $provider = $this->registry->get($integration->provider_identifier);

            // Re-read INSIDE this transaction, after the row lock above
            // has resolved — never from the pre-transaction $posts
            // snapshot (review finding 2). A concurrent run for this
            // exact channel can only ever be another instance blocked on
            // the SAME lockForUpdate() above; by the time this SELECT
            // runs, any such instance has either committed (so this
            // query sees its freshly-published prefix) or rolled back
            // (nothing changed). Trusting the pre-lock snapshot instead
            // would leave $anchor null after a concurrent partial
            // success, and the next segment would go out as a fresh,
            // DETACHED root — exactly the duplicate-thread outcome
            // PostPayloadDto's docblock calls the worst one.
            $anchor = Post::withoutGlobalScope('organization')
                ->where('post_group_id', $postGroupId)
                ->where('integration_id', $integrationId)
                ->whereNotNull('release_id')
                ->orderByDesc('order')
                ->value('release_id');

            $chain = $this->buildChain($pending, $anchor, $preparedMedia);

            try {
                // Raised here, not where it was caught: a media failure
                // is a failure to publish this channel, and every kind of
                // failure to publish this channel is settled by the same
                // catch arms below, under these row locks.
                if ($preparationFailure !== null) {
                    throw $preparationFailure;
                }

                $results = $provider->post($integration, $chain);

                // array_key_exists(), not isset()/direct indexing
                // (review Minor 6): a provider returning a short array —
                // a contract violation `SocialProviderInterface::post()`
                // forbids but this class must not simply trust — must
                // NEVER throw here. Throwing after the provider call
                // already returned would unwind this transaction and
                // discard every release_id already resolved from
                // $results for a chain segment that IS already live on
                // the network, turning a provider bug into a duplicate
                // publish on the very next run. Missing indices are
                // instead treated exactly like a partial failure: left
                // untouched (still QUEUE), retried later with the
                // correct anchor once resolved.
                $allResolved = true;
                foreach ($pending as $index => $post) {
                    if (array_key_exists($index, $results)) {
                        $this->markPublished($post, $results[$index]);
                    } else {
                        $allResolved = false;
                    }
                }

                return [! $allResolved, null];
            } catch (ProviderPartialFailureException $e) {
                foreach ($pending as $index => $post) {
                    if (isset($e->published[$index])) {
                        $this->markPublished($post, $e->published[$index]);
                    }
                }

                // See class docblock: a partial failure carries no
                // reason distinguishable from transient — it is retried,
                // never marked ERROR on its own authority.
                return [true, null];
            } catch (ProviderRateLimitException $e) {
                // 0 means "the network told us nothing" — final-review
                // Important 4. ProviderRateLimitException::$retryAfterSeconds
                // is a non-nullable int and every provider defaults it to
                // 0 when the Retry-After header or the equivalent response
                // field is absent, which Slack, Mastodon and Bluesky 429s
                // frequently are. Normalising it to null HERE, at the only
                // layer that knows the difference between "wait zero
                // seconds" and "no figure was given", is what stops it
                // from (a) winning publishGroup()'s min() across channels
                // against a sibling channel's real figure, and (b)
                // reaching PublishPostJob::handle() as a literal 0 —
                // which released the job with no delay at all, burning
                // every remaining attempt within milliseconds of the first
                // 429 and turning a transient rate limit into a permanent,
                // customer-visible ERROR (having hammered the
                // rate-limited API three more times first).
                return [true, $e->retryAfterSeconds > 0 ? $e->retryAfterSeconds : null];
            } catch (ProviderTransientException) {
                return [true, null];
            } catch (MediaTemporaryFileException $e) {
                // The attachment could not be read out of storage at all
                // (missing object, storage outage). Permanent for this
                // run: a retry re-reads the same key, and the message
                // names only the media id — never the storage key or the
                // object's contents — because it lands in `posts.error`
                // and is returned by `PostResource`.
                $this->markAllError($pending, $e->getMessage());

                return [false, null];
            } catch (ProviderPermanentException $e) {
                $this->markAllError($pending, $e->getMessage());

                return [false, null];
            } catch (ProviderAuthException $e) {
                $integration->refresh_needed = true;
                $integration->save();

                $this->markAllError($pending, $e->getMessage());

                return [false, null];
            }
        });
    }

    /**
     * Cheap, unlocked "is this run still entitled to publish at all?" —
     * the same three predicates `publishLockedChannel()` re-checks under
     * the lock (QUEUE, matching generation, actually due). Answering
     * `false` here only skips media preparation; answering `true` grants
     * nothing, because nothing publishes outside that transaction.
     */
    private function stillPublishable(string $postGroupId, ?int $queueVersion): bool
    {
        /** @var PostGroup|null $group */
        $group = PostGroup::withoutGlobalScope('organization')
            ->whereKey($postGroupId)
            ->first();

        if ($group === null || $group->state !== PostState::QUEUE) {
            return false;
        }

        if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
            return false;
        }

        return $this->isDueNow($group);
    }

    /**
     * Resolves this channel's integration and provider outside any
     * transaction, for the media preparation that has to happen before
     * one opens. Returns `[null, null]` when the integration is gone —
     * the transaction re-reads it and owns that outcome (see
     * publishLockedChannel()); this method never decides anything.
     *
     * @return array{0: Integration|null, 1: SocialProviderInterface|null}
     */
    private function channelProvider(string $integrationId): array
    {
        /** @var Integration|null $integration */
        $integration = Integration::withoutGlobalScope('organization')->find($integrationId);

        if ($integration === null) {
            return [null, null];
        }

        return [$integration, $this->registry->get($integration->provider_identifier)];
    }

    /**
     * Makes every attachment on every pending segment actually
     * publishable — final-review batch 2, Critical 3 — and returns
     * `[dtosByPostId, temporaryFilePaths]`.
     *
     * Two steps, in this order, per attachment:
     *
     *  1. Copy the object off its storage disk into a real local file
     *     (`MediaTempFiles`) and point the DTO at THAT path. Every
     *     provider reads its bytes from the path — `file_get_contents()`,
     *     `filesize()`, `basename()` — and `Media::$path` is an S3/MinIO
     *     key, so before this step Telegram and Discord were sending
     *     zero-byte attachments.
     *  2. If the provider implements `SupportsMedia`, pre-upload it and
     *     keep the returned DTO (with its `$remoteId`), because
     *     Mastodon/Bluesky/Slack reference an already-uploaded handle
     *     from the post body and refuse — permanently — to publish a
     *     media DTO without one. Nothing in `app/` had ever made this
     *     call; `SupportsMedia`'s docblock claimed the editor did, and no
     *     such call existed anywhere.
     *
     * The caller discards every returned temp path in a `finally`,
     * including on the failure paths: this method's own `throw` still
     * returns nothing, so it cleans up what it created before rethrowing.
     *
     * @param  Collection<int, Post>  $pending
     * @return array{0: array<string, list<MediaDto>>, 1: list<string>}
     */
    private function prepareMedia(SocialProviderInterface $provider, Integration $integration, Collection $pending): array
    {
        $prepared = [];
        $temporaryFiles = [];

        try {
            foreach ($pending as $post) {
                $dtos = [];

                foreach ($post->media as $media) {
                    $localPath = $this->mediaFiles->copy($media);
                    $temporaryFiles[] = $localPath;

                    $dto = $media->toDto()->withPath($localPath);

                    if ($provider instanceof SupportsMedia) {
                        $dto = $provider->uploadMedia($integration, $dto);
                    }

                    $dtos[] = $dto;
                }

                if ($dtos !== []) {
                    $prepared[$post->id] = $dtos;
                }
            }
        } catch (\Throwable $e) {
            $this->mediaFiles->discard($temporaryFiles);

            throw $e;
        }

        return [$prepared, $temporaryFiles];
    }

    /**
     * @param  Collection<int, Post>  $pending
     * @param  array<string, list<MediaDto>>  $preparedMedia  Keyed by post id — see prepareMedia(). A segment with no attachments simply has no entry.
     * @return list<PostPayloadDto>
     */
    private function buildChain(Collection $pending, ?string $anchor, array $preparedMedia = []): array
    {
        $chain = [];

        foreach ($pending as $index => $post) {
            $payload = new PostPayloadDto(
                content: $post->content,
                // Never `$media->toDto()` directly: that DTO points at a
                // storage key no provider can read, and carries no
                // pre-upload handle. prepareMedia() is the only thing
                // that produces a publishable MediaDto.
                media: $preparedMedia[$post->id] ?? [],
                settings: $post->settings ?? [],
            );

            if ($index === 0 && $anchor !== null) {
                $payload = $payload->withReplyToReleaseId($anchor);
            }

            $chain[] = $payload;
        }

        return $chain;
    }

    private function markPublished(Post $post, PostResultDto $result): void
    {
        $post->release_id = $result->releaseId;
        $post->release_url = $result->releaseUrl;
        $post->published_at = now();
        $this->stateMachine->transition($post, PostState::PUBLISHED);
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    private function markAllError(Collection $posts, string $message): void
    {
        foreach ($posts as $post) {
            $post->error = $message;
            $this->stateMachine->transition($post, PostState::ERROR);
        }
    }

    /**
     * Reached only when no channel needs a retry — every post in the
     * group has settled into either `PUBLISHED` or `ERROR` for this run.
     * PUBLISHED iff every single one of them is; otherwise ERROR (brief
     * point 9).
     *
     * Takes an ID and re-reads the row under `lockForUpdate()` rather
     * than accepting the caller's in-memory `PostGroup` — final-review
     * fix, Critical 1, reproduced with two forked processes against real
     * Postgres (`PublishRaceTest`). `publishGroup()` locks the group in
     * `lockGroupIfQueued()` and COMMITS that lock immediately (the
     * per-channel commit boundary — see the class docblock), so by the
     * time this runs, at the very end of a run that spans several
     * provider calls, the caller's instance can be arbitrarily stale.
     * `PostStateMachine::transition()` validates only the IN-MEMORY
     * `$from` and then issues a blind `save()` (UPDATE by primary key),
     * so this method used to overwrite whatever a concurrent writer had
     * committed in between. The observed outcome: an edit landing
     * mid-run (`PostGroupService::update()`, allowed through because no
     * channel had committed a `release_id` yet) recreated the group's
     * posts as `QUEUE` at `queue_version` 2 — and then THIS method,
     * still holding the version-1 instance, stamped the group `ERROR`.
     * The version-2 job then found `ERROR != QUEUE` and no-opped, and
     * nothing recovers that: `MissedPostsService::redispatchOrphaned()`
     * scans `state = QUEUE` only. The customer's edited post is lost,
     * permanently and silently.
     *
     * Both predicates are load-bearing and both are re-checked HERE,
     * under the lock, not by the caller beforehand:
     *
     *  - `state === QUEUE`: another run (or `abandonGroup()`) may already
     *    have settled this group. Nothing may transition it out of a
     *    terminal state, and `PostStateMachine` would throw on
     *    `PUBLISHED -> *` anyway — after this run's own writes, from
     *    inside the caller's transaction in `abandonGroup()`'s case.
     *  - `queue_version === $queueVersion`: the group has since been
     *    superseded by a NEW schedule generation, which owns the group's
     *    final state now. This run's verdict is about rows that generation
     *    has already replaced or rescheduled, and must be discarded
     *    entirely. `null` means "no opinion" (see `publishGroup()`), and
     *    then only the state check applies.
     *
     * Lock ORDER is preserved: group first, posts second — the same order
     * `PostGroupService::lockGroup()` -> `guardEditable()` and
     * `abandonGroup()` take, so this introduces no new deadlock cycle.
     * `abandonGroup()` calls this from INSIDE its own transaction, which
     * already holds this exact row lock; re-taking a lock the same
     * transaction holds is free, and the nested `DB::transaction()` is a
     * savepoint, not a second top-level transaction.
     */
    private function finalizeGroupState(string $postGroupId, ?int $queueVersion): void
    {
        DB::transaction(function () use ($postGroupId, $queueVersion): void {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
                return;
            }

            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                return;
            }

            $hasUnpublished = Post::withoutGlobalScope('organization')
                ->where('post_group_id', $group->id)
                ->where('state', '!=', PostState::PUBLISHED->value)
                ->exists();

            $this->stateMachine->transition($group, $hasUnpublished ? PostState::ERROR : PostState::PUBLISHED);
        });
    }
}
