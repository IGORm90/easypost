<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Exceptions\ProviderAuthException;
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
 *    documents for `Integration`. This class adds a second, narrower
 *    layer on top: `publishChannel()` re-locks (`lockForUpdate()`)
 *    exactly the post rows it is about to publish, inside the SAME
 *    transaction that calls the provider and persists the result, so a
 *    second caller racing for the identical channel blocks until the
 *    first either commits (and the second then sees `release_id` already
 *    set and skips) or rolls back (nothing was sent, safe to retry).
 *    Deliberately scoped to one channel's pending rows, not the whole
 *    group and not the whole run — see the next section for why holding
 *    one lock across the ENTIRE run (all channels, one HTTP call each)
 *    was rejected.
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
    ) {}

    /**
     * @throws PublishRetryNeededException when at least one channel needs
     *                                     to be attempted again later —
     *                                     caught only by `PublishPostJob`.
     */
    public function publishGroup(string $postGroupId): void
    {
        $group = $this->lockGroupIfQueued($postGroupId);

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

        $this->finalizeGroupState($group);
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
    public function abandonGroup(string $postGroupId, string $reason): void
    {
        DB::transaction(function () use ($postGroupId, $reason): void {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
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

            $this->finalizeGroupState($group);
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
    private function lockGroupIfQueued(string $postGroupId): ?PostGroup
    {
        return DB::transaction(function () use ($postGroupId): ?PostGroup {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
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
     * @return SupportCollection<string, Collection<int, Post>>
     */
    private function loadChains(PostGroup $group): SupportCollection
    {
        /** @var Collection<int, Post> $posts */
        $posts = Post::withoutGlobalScope('organization')
            ->where('post_group_id', $group->id)
            ->with(['media' => fn ($query) => $query->withoutGlobalScope('organization')])
            ->orderBy('order')
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
     * @return array{0: bool, 1: ?int}
     */
    private function publishChannel(string $postGroupId, string $integrationId, Collection $posts): array
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

        return DB::transaction(function () use ($postGroupId, $integrationId, $pending): array {
            // Re-lock exactly the rows about to be published, inside the
            // SAME transaction as the provider call and its persistence
            // — see the class docblock's idempotency section, point 4.
            $lockedIds = Post::withoutGlobalScope('organization')
                ->whereIn('id', $pending->pluck('id'))
                ->whereNull('release_id')
                ->lockForUpdate()
                ->pluck('id');

            if ($lockedIds->isEmpty()) {
                // A concurrent run already finished this channel between
                // this method's initial (unlocked) check and this
                // transaction acquiring the lock.
                return [false, null];
            }

            $pending = $pending->filter(fn (Post $post): bool => $lockedIds->contains($post->id))->values();

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

            $chain = $this->buildChain($pending, $anchor);

            try {
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
                return [true, $e->retryAfterSeconds];
            } catch (ProviderTransientException) {
                return [true, null];
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
     * @param  Collection<int, Post>  $pending
     * @return list<PostPayloadDto>
     */
    private function buildChain(Collection $pending, ?string $anchor): array
    {
        $chain = [];

        foreach ($pending as $index => $post) {
            $payload = new PostPayloadDto(
                content: $post->content,
                media: $post->media
                    ->map(fn (Media $media): MediaDto => $media->toDto())
                    ->all(),
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
     */
    private function finalizeGroupState(PostGroup $group): void
    {
        $hasUnpublished = Post::withoutGlobalScope('organization')
            ->where('post_group_id', $group->id)
            ->where('state', '!=', PostState::PUBLISHED->value)
            ->exists();

        $this->stateMachine->transition($group, $hasUnpublished ? PostState::ERROR : PostState::PUBLISHED);
    }
}
