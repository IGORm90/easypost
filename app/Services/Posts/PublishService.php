<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *    confirmed it went out, see `markPostPublished()` — is never included in
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
 *    rejected. `queue_version` (Task 31, compared in `PostGroupLifecycle::claim()`)
 *    is a THIRD, independent layer, not a substitute for either of the
 *    two above: it stops a job from a SUPERSEDED schedule generation from
 *    ever reaching `publishChannel()` at all (a reschedule case), whereas
 *    the two layers above stop two genuinely-concurrent SAME-generation
 *    runs from double-publishing a segment. All three hold simultaneously.
 *
 *    This re-lock's `WHERE` MUST filter on `state = QUEUE` as well as
 *    `release_id IS NULL` — post-review fix, not a simplification to
 *    ever reverse. `release_id IS NULL` alone is not sufficient: a
 *    concurrent `PostGroupLifecycle::abandon()` run (`PublishPostJob::failed()`, once
 *    retries are exhausted) can mark a channel's still-unpublished rows
 *    `ERROR` — `release_id` stays null, only `state` changes — between
 *    this method's unlocked pre-check and this transaction acquiring the
 *    lock. Without the `state` predicate, this method would still pick
 *    those `ERROR` rows up, call the provider, and `markPostPublished()`
 *    would then attempt `ERROR -> PUBLISHED`, a transition
 *    `PostGroupLifecycle` rejects — throwing INSIDE this transaction AFTER
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
 * and `settle()`'s in-transaction re-checks are what
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
 * index present in `$published` is persisted via `markPostPublished()` —
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
 * is exactly the last one `markPostPublished()` touched) to use as
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
    public function __construct(
        private readonly ChannelAttempt $attempt,
        private readonly PostGroupLifecycle $lifecycle,
        private readonly RecurrenceService $recurrence,
    ) {}

    /**
     * @param  int|null  $queueVersion  Task 31: the `post_groups.queue_version`
     *                                  this call was dispatched for — see
     *                                  `PostGroupLifecycle::claim()`'s docblock. Null
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
        $group = $this->lifecycle->claim($postGroupId, $queueVersion);

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

        $outcome = $this->lifecycle->settle($group->id, $queueVersion);

        // Block 4. The successor of a recurring post is created here and
        // nowhere else — AFTER settle()'s transaction has
        // committed, and only when this run is the one that actually
        // moved the group to PUBLISHED.
        //
        // After the commit, for the same reason PostGroupService only
        // ever dispatches PublishPostJob post-commit (see its docblock):
        // spawnRecurrence() enqueues a job for the new occurrence, and a
        // job enqueued from inside an open transaction can be picked up
        // by a worker before the row it refers to is visible.
        //
        // Only on PUBLISHED, and only on THIS run's own verdict. A null
        // outcome means some other run (or PostGroupLifecycle::abandon()) had already
        // settled the group, or this run's queue_version was superseded
        // — in both cases the decision is not ours to act on. An ERROR
        // outcome deliberately spawns nothing: a series that failed
        // stops rather than marching on, the author is already told by
        // PostFailedNotification, and a successful manual retry resumes
        // it through this same line, because `repeat_spawned_at` is
        // still null.
        //
        // spawnNext() never throws — see its docblock. The post is
        // already out on the network by this point, and nothing about
        // scheduling the NEXT one may be allowed to report the last one
        // as failed.
        if ($outcome === PostState::PUBLISHED) {
            $this->recurrence->spawnNext($group->id);
        }
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
            return [false, null];
        }

        if (! $this->stillPublishable($postGroupId, $queueVersion)) {
            return [false, null];
        }

        $prepared = $this->attempt->prepare($integrationId, $pending);

        try {
            return $this->publishLockedChannel($postGroupId, $integrationId, $pending, $queueVersion, $prepared);
        } finally {
            $this->attempt->discard($prepared);
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
     * @return array{0: bool, 1: ?int}
     */
    private function publishLockedChannel(
        string $postGroupId,
        string $integrationId,
        Collection $pending,
        ?int $queueVersion,
        PreparedChannel $prepared,
    ): array {
        // LOG_PLAN.md Phase 1.5: captured by reference and logged AFTER
        // the transaction commits, never inside it — a Log call that
        // can throw has no business inside a transaction that decides
        // PUBLISHED, which is terminal. Left null for every early abort
        // above (lockedIds empty, group gone/not QUEUE, version
        // mismatch, not due yet): nothing about THIS channel was
        // decided in those cases, so there is nothing to report as an
        // attempt.
        $logged = null;

        $result = DB::transaction(function () use ($postGroupId, $integrationId, $pending, $queueVersion, $prepared, &$logged): array {
            $lockedIds = Post::withoutGlobalScope('organization')
                ->whereIn('id', $pending->pluck('id'))
                ->whereNull('release_id')
                ->where('state', PostState::QUEUE->value)
                ->lockForUpdate()
                ->pluck('id');

            if ($lockedIds->isEmpty()) {
                return [false, null];
            }

            $pending = $pending->filter(fn (Post $post): bool => $lockedIds->contains($post->id))->values();

            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
                return [false, null];
            }

            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                return [false, null];
            }

            if (! $this->lifecycle->isDueNow($group)) {
                return [false, null];
            }

            $outcome = $this->attempt->send($integrationId, $postGroupId, $pending, $prepared);

            foreach ($outcome->published as $index => $published) {
                $this->lifecycle->markPostPublished($pending[$index], $published);
            }

            if ($outcome->kind === ChannelOutcomeKind::Failed) {
                $this->lifecycle->markPostsError($pending, (string) $outcome->errorMessage);
            }

            if ($outcome->flagsRefresh && $outcome->integration !== null) {
                $outcome->integration->refresh_needed = true;
                $outcome->integration->save();
            }

            if ($outcome->integration !== null) {
                $logged = $this->publishAttemptOutcome(
                    $outcome->integration,
                    $postGroupId,
                    $group,
                    $outcome->kind->value,
                    $outcome->failureKind,
                );
            }

            return [$outcome->kind === ChannelOutcomeKind::Retry, $outcome->retryAfterSeconds];
        });

        if ($logged !== null) {
            Log::channel('jobs')->info('publish.attempt', $logged);
        }

        return $result;
    }

    /**
     * @return array{provider: string, integration_id: string, post_group_id: string, result: string, failure_kind: ?string, lateness_ms: int}
     */
    private function publishAttemptOutcome(
        Integration $integration,
        string $postGroupId,
        PostGroup $group,
        string $result,
        ?string $failureKind,
    ): array {
        return [
            'provider' => $integration->provider_identifier,
            'integration_id' => $integration->id,
            'post_group_id' => $postGroupId,
            'result' => $result,
            'failure_kind' => $failureKind,
            // absolute: true — Carbon 3 defaults diffIn*() to a SIGNED
            // result, and this is always reported as a magnitude.
            'lateness_ms' => (int) now()->diffInMilliseconds($group->publish_date, true),
        ];
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

        return $this->lifecycle->isDueNow($group);
    }
}
