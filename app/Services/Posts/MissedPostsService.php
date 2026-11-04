<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostGroup;
use App\Notifications\PostFailedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Task 32's safety net — everything `ScanMissedPostsJob` (dispatched
 * every 5 minutes, see routes/console.php) actually does. Two
 * completely independent passes, run every scan:
 *
 *  1. `redispatchOrphaned()` — groups stuck at `QUEUE` with a
 *     `publish_date` well in the past, re-issued a `PublishPostJob`.
 *  2. `notifyFailed()` — groups that have already settled to `ERROR`
 *     (via `PublishService::PostGroupLifecycle::abandon()`/`finalizeGroupState()`, from
 *     EITHER the original publish attempt or a scan-triggered
 *     redispatch — this class does not care which), emailing the author
 *     exactly once.
 *
 * ============================================================
 * Why this class exists — the two holes `PublishService::
 * PostGroupLifecycle::claim()`'s docblock documents
 * ============================================================
 *
 * (1) A crash or Redis outage between `PostGroupService`'s transaction
 *     commit and its `PublishPostJob::dispatch()` call leaves a group at
 *     `QUEUE` on a freshly-bumped `queue_version`, with no job ever
 *     reaching the queue for it at all — nothing publishes, nothing
 *     reaches `failed_jobs`, and the calendar still renders it as
 *     "still scheduled". (2) `ShouldBeUnique` can swallow a dispatch in
 *     any path that does not force-release first — the reschedule paths
 *     do (see `PostGroupService::dispatchPublishIfQueued()`), but that
 *     is a patch on those specific call sites, not a proof that no
 *     future caller can hit the same swallow. Both failure modes are
 *     observably identical: a group sitting at `QUEUE`, `publish_date`
 *     in the past, no live job working it. `redispatchOrphaned()` is
 *     what finds that state and recovers from it.
 *
 * ============================================================
 * Runs with NO OrganizationContext set
 * ============================================================
 *
 * `ScanMissedPostsJob` is a scheduled job, not a request — see
 * AppServiceProvider's queue-event listeners, which clear
 * OrganizationContext around every job boundary. Every query against a
 * `BelongsToOrganization` model in this class therefore goes through
 * `withoutGlobalScope('organization')` BY NAME, never the blanket
 * `withoutGlobalScopes()` — identical to
 * `TokenRefreshService::dueForRefresh()` (Task 16) and
 * `PublishService`'s own queries (Task 30/31), which this class's tests
 * copy the "no organization context set" enumeration pattern from.
 *
 * ============================================================
 * Why this class no longer probes ShouldBeUnique before redispatching
 * (post-review fix, round 3)
 * ============================================================
 *
 * Earlier revisions probed `PublishPostJob`'s `ShouldBeUnique` lock
 * before ever calling `dispatch()`, and — if the lock was held —
 * deferred to a `lockCouldStillBeLegitimate()` bound before deciding
 * whether to force-release and act. Both existed to avoid disturbing a
 * job legitimately still cycling through its own retry backoff
 * (`PublishPostJob::$backoff` is `[60, 300, 900]` across 4 tries — up to
 * ~21 minutes of genuinely-in-flight time). That mechanism is gone now,
 * because it made this codebase's own explicit ≤5-minute recovery
 * guarantee impossible to honour: ANY bound long enough to never
 * mistake a real 21-minute retry for a leak is also long enough to make
 * recovery wait that long — worse, once queue_version became part of
 * `PublishPostJob::uniqueId()` (see that class's docblock), the
 * lock survives a total loss of the QUEUE Redis instance whenever the
 * `ShouldBeUnique` lock itself lives on a SEPARATE, undamaged cache
 * instance (see `docs/DEPLOYMENT.md`'s queue/cache split) — the probe
 * would then see "held, plausible" and refuse to act for the ENTIRE
 * legitimacy window, every single scan, until that window elapsed.
 *
 * The fix removes the probe rather than re-tuning it again: once
 * `queue_version` is bumped before every redispatch, this class's own
 * dispatch can NEVER collide with whatever the OLD generation's lock is
 * doing, whether that lock is legitimately held, leaked, or long gone —
 * a version-aware key means there is nothing left to probe FOR. If the
 * old job genuinely is still alive and racing the new one,
 * `PublishRaceTest`'s "two genuinely concurrent `publishGroup()` runs
 * for the same group publish each segment exactly once" already proves
 * that outcome safe (per-row `lockForUpdate()` + `release_id IS NULL`
 * is the layer that actually prevents a duplicate provider call, not
 * `ShouldBeUnique` — see `PublishService`'s own idempotency-accounting
 * docblock), and the OLD job's own next `queue_version` re-check
 * (`PostGroupLifecycle::claim()`/`finalizeGroupState()`) finds itself stale and
 * cleanly no-ops, now with a log line. The cost this accepts: a post
 * legitimately deep in its own backoff (attempt 3-4, 6-21 minutes in)
 * may occasionally get one redundant parallel generation — never a
 * duplicate publish, just one wasted `dispatch_attempts` tick and one
 * extra provider call attempt. That trade was made deliberately, not
 * overlooked: the alternative (any probe-based delay at all) cannot
 * satisfy both "≤5 minutes" and "never disturb a legitimate retry"
 * simultaneously, and the literal SLA wins.
 *
 * `dispatchRedispatch()` bumps `queue_version` on every call — not a
 * reschedule in the `PostGroupService` sense, but a genuinely new
 * generation all the same, and it needs its own distinct lock key so it
 * is never blocked by whatever is left of the OLD generation's lock.
 */
class MissedPostsService
{
    /**
     * Bounded batch size for both passes below — this runs unattended,
     * every 5 minutes, forever; an unbounded `get()` would let one
     * scan's memory footprint grow without limit as the backlog grows,
     * which is exactly the kind of failure mode a safety-net job must
     * not itself introduce.
     */
    private const int CHUNK_SIZE = 100;

    public function scan(): void
    {
        $this->redispatchOrphaned();
        $this->notifyFailed();
    }

    /**
     * Every `QUEUE` group across every tenant not durably known to have
     * been dispatched recently. `chunkById()`, not `get()`/`cursor()` —
     * see this class's docblock ("bounded batch size"). Ordered
     * implicitly by `chunkById()`'s own primary-key cursor, which is all
     * this needs: there is no priority ordering requirement here, only
     * "eventually get to every candidate row".
     *
     * Durable-scheduling rewrite: the candidate predicate is now
     * `last_dispatched_at` — stamped, atomically, at the exact moment
     * ANY of this codebase's dispatch sites (`DueDispatchService`,
     * `PostGroupLifecycle::dispatch()`, this class's own
     * `dispatchRedispatch()`) actually claims a row into `QUEUE` — not
     * `publish_date`, which no longer bears any fixed relationship to
     * when a group was claimed (a group can sit `SCHEDULED` for weeks
     * before `DueDispatchService` ever touches it). `publishing.stuck_after_seconds`
     * (default 300s = 5 minutes), together with `->everyFiveMinutes()`'s
     * own scan cadence (`routes/console.php`), is the WHOLE ≤5-minute
     * recovery guarantee — a stuck row is claimed on the very next tick
     * that could possibly see it, unconditionally (see the class
     * docblock for why there is no legitimacy check gating that any
     * more).
     *
     * `whereNull('last_dispatched_at')` alongside the staleness check:
     * a row can reach `QUEUE` with `last_dispatched_at` still null if
     * the process crashes between `PostGroupLifecycle::dispatch()`'s
     * caller committing the state transition and that method's own
     * (separate, post-commit) `last_dispatched_at` write — exactly Task
     * 32's original "hole 1" (a crash between commit and dispatch),
     * still possible for the immediate-dispatch paths even though
     * `DueDispatchService`'s own claim writes both columns in the SAME
     * atomic statement. Treating null as "immediately eligible" (rather
     * than excluding it, which a bare `<` comparison against NULL would
     * do in SQL) is what keeps this class covering that hole.
     */
    private function redispatchOrphaned(): void
    {
        $threshold = now()->subSeconds((int) config('publishing.stuck_after_seconds'));

        PostGroup::withoutGlobalScope('organization')
            ->where('state', PostState::QUEUE->value)
            ->where(function (Builder $query) use ($threshold): void {
                $query->whereNull('last_dispatched_at')
                    ->orWhere('last_dispatched_at', '<', $threshold);
            })
            ->chunkById(self::CHUNK_SIZE, function (Collection $groups): void {
                foreach ($groups as $group) {
                    $this->redispatchIfOrphaned($group);
                }
            });
    }

    /**
     * See the class docblock for the full reasoning. Attempt cap first —
     * `dispatch_attempts` is incremented on every claim, this class's
     * redispatches included, so a group that has already been
     * (re)dispatched `max_dispatch_attempts` times is a bug, not a
     * transient failure that one more redispatch might fix. Abandoning
     * it here — rather than after yet another doomed attempt exhausts
     * its OWN retries and comes back to this same scan — is what
     * actually stops the loop: an abandoned group settles to `ERROR`
     * and stops matching this method's caller's query entirely
     * (state != QUEUE), so it is never looked at again.
     *
     * No lock probe, no legitimacy check: once a row is flagged stuck by
     * `redispatchOrphaned()`'s own query, this always bumps
     * `queue_version` and redispatches — see the class docblock for why
     * that is safe unconditionally now that `PublishPostJob::uniqueId()`
     * is version-aware, and why any probe-then-wait step made this
     * codebase's own ≤5-minute recovery guarantee impossible to keep.
     */
    private function redispatchIfOrphaned(PostGroup $group): void
    {
        $maxAttempts = (int) config('publishing.max_dispatch_attempts');

        if ($group->dispatch_attempts >= $maxAttempts) {
            Log::error('missed_posts.attempt_cap_exceeded', [
                'post_group_id' => $group->id,
                'queue_version' => $group->queue_version,
                'dispatch_attempts' => $group->dispatch_attempts,
                'max_dispatch_attempts' => $maxAttempts,
            ]);

            app(PostGroupLifecycle::class)->abandon(
                $group->id,
                'This post could not be published after repeated attempts and was not sent.',
                $group->queue_version,
            );

            return;
        }

        $this->dispatchRedispatch($group, 'missed_posts.redispatched');
    }

    /**
     * Redispatches an orphaned group under a NEW queue_version (the bump and the
     * dispatch bookkeeping are `PostGroupLifecycle::redispatchOrphan()`'s atomic
     * write), then dispatches the job for the version that was actually
     * committed and logs it.
     */
    private function dispatchRedispatch(PostGroup $group, string $logMessage): void
    {
        $newQueueVersion = app(PostGroupLifecycle::class)->redispatchOrphan($group);

        PublishPostJob::dispatch($group->id, $newQueueVersion);

        Log::warning($logMessage, [
            'post_group_id' => $group->id,
            'queue_version' => $newQueueVersion,
            'publish_date' => $group->publish_date?->toIso8601String(),
        ]);
    }

    /**
     * Every `ERROR` group, across every tenant, not yet notified.
     * Deliberately independent of `redispatchOrphaned()` above — a group
     * can reach `ERROR` via the ORIGINAL publish attempt's own retries
     * exhausting (no scan involvement at all), and this pass must catch
     * that just as reliably as one this class itself redispatched. This
     * is also precisely why the notification does not live inside
     * `redispatchIfOrphaned()`/`PublishPostJob::failed()`: see the class
     * docblock's "do not fight the exhaustion path" framing in the task
     * brief — `PostGroupLifecycle::abandon()` already owns the QUEUE->ERROR transition
     * exclusively, this method only ever REACTS to that outcome, on its
     * own schedule, never causes it.
     */
    private function notifyFailed(): void
    {
        PostGroup::withoutGlobalScope('organization')
            ->where('state', PostState::ERROR->value)
            ->whereNull('notified_at')
            ->with('creator')
            ->chunkById(self::CHUNK_SIZE, function (Collection $groups): void {
                foreach ($groups as $group) {
                    $this->notifyOne($group);
                }
            });
    }

    /**
     * `notified_at` is set unconditionally here, even when `$group` has
     * no `creator` (a nullable, `nullOnDelete()` foreign key — see the
     * `post_groups` migration) — an author-less group can never be
     * notified no matter how many more scans run, so leaving
     * `notified_at` null for it would just re-process it forever for no
     * benefit. `error` is populated here too: this is the first, only
     * production writer of `post_groups.error` — see this class's
     * docblock and `buildErrorSummary()` below for why the value it
     * writes is a short customer-facing sentence, not a raw exception
     * message.
     *
     * The claim step is a single conditional `UPDATE ... WHERE state =
     * 'ERROR' AND notified_at IS NULL`, not a read-then-`save()` —
     * post-review fix (finding 2). `ScanMissedPostsJob implements
     * ShouldBeUnique` now makes two full scan EXECUTIONS overlapping
     * unlikely, but that lock is a second layer, not the only one this
     * method should lean on (see that job's and routes/console.php's
     * docblocks for why `withoutOverlapping()`/`onOneServer()` alone do
     * not cover a `Schedule::job()` push the way they cover a
     * `Schedule::command()` run). A plain `UPDATE`'s `WHERE` clause is
     * re-evaluated by Postgres against the row's current, post-lock-wait
     * value before the write applies — the standard guarantee every
     * `UPDATE` statement gives against a concurrent writer, independent
     * of any scheduler-level mutex — so of two overlapping calls racing
     * on the SAME group, only the one that is still first to see
     * `notified_at IS NULL` at the moment its `UPDATE` actually executes
     * affects a row; the other affects zero rows and must not send
     * anything.
     *
     * `state = 'ERROR'` in that same `WHERE` — post-review fix, round 2
     * (folded into finding 2's original claim, since leaving it out
     * reintroduces finding 1's Critical): `notifyFailed()`'s own SELECT
     * and this claim's UPDATE are two separate statements, not one
     * transaction, so a retry can land in between — the same
     * `bumpQueueVersionIfQueuing()` call that clears `notified_at` also
     * moves `state` back to `QUEUE`. Without this predicate, a claim
     * landing in exactly that gap would stamp `notified_at` onto a row
     * that is no longer `ERROR` at all, immediately after the reset that
     * was supposed to make this group notifiable again — silently
     * consuming the "notify once" slot for a retry that has not even
     * failed yet, so the NEXT genuine failure never gets an email or an
     * `error` message either. Re-asserting `state = 'ERROR'` here makes
     * the claim a no-op in that window instead (0 rows affected,
     * `$claimed !== 1`, nothing sent) — exactly like losing the race to
     * another scan.
     */
    private function notifyOne(PostGroup $group): void
    {
        $error = $group->error ?? $this->buildErrorSummary($group);

        $claimed = PostGroup::withoutGlobalScope('organization')
            ->whereKey($group->id)
            ->where('state', PostState::ERROR->value)
            ->whereNull('notified_at')
            ->update(['error' => $error, 'notified_at' => now()]);

        if ($claimed !== 1) {
            // Lost the race to another overlapping scan (or the group
            // was already notified by the time this UPDATE ran) — that
            // other execution already sent (or is sending) the one
            // notification this group gets. Sending here too would be
            // exactly the double-email this fix exists to prevent.
            return;
        }

        if ($group->creator !== null) {
            $group->error = $error;
            $group->creator->notify(new PostFailedNotification($group));
        }
    }

    /**
     * A short, customer-facing sentence naming the channel(s) that
     * failed — never the raw provider/`MaxAttemptsExceededException`
     * message `Post::$error`/`PostGroupLifecycle::abandon()`'s `$reason` carries
     * internally (see `PublishService::markAllError()` and
     * `PublishPostJob::failed()`), which can be arbitrary provider text
     * a customer has no context to interpret. `unique('integration_id')`
     * so a multi-segment thread on one channel is named once, not once
     * per failed segment.
     *
     * The eager-loaded `integration` relation needs its OWN
     * `withoutGlobalScope('organization')` — `Post::withoutGlobalScope()`
     * above only affects the `posts` query itself; `Integration` is a
     * separate model with its own scope registration, and would
     * otherwise fail closed to null with no `OrganizationContext` set
     * (see `BelongsToOrganization`), turning `$post->integration->name`
     * below into a hard `TypeError` rather than the customer-visible
     * channel name it is supposed to be. `integration_id` is a
     * non-nullable, `restrictOnDelete()` foreign key (see the `posts`
     * migration), so — once correctly unscoped — `->integration` is
     * never actually null here.
     */
    private function buildErrorSummary(PostGroup $group): string
    {
        $failedChannels = Post::withoutGlobalScope('organization')
            ->where('post_group_id', $group->id)
            ->where('state', PostState::ERROR->value)
            ->with(['integration' => fn ($query) => $query->withoutGlobalScope('organization')])
            ->get()
            ->unique('integration_id')
            ->map(fn (Post $post): string => $post->integration->name)
            ->values();

        if ($failedChannels->isEmpty()) {
            return 'Publishing failed for this post.';
        }

        return 'Publishing failed on the following channel(s): '.$failedChannels->implode(', ').'.';
    }
}
