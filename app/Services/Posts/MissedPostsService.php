<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\PostGroup;
use App\Notifications\PostFailedNotification;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Task 32's safety net — everything `ScanMissedPostsJob` (dispatched
 * every 10 minutes, see routes/console.php) actually does. Two
 * completely independent passes, run every scan:
 *
 *  1. `redispatchOrphaned()` — groups stuck at `QUEUE` with a
 *     `publish_date` well in the past, re-issued a `PublishPostJob`.
 *  2. `notifyFailed()` — groups that have already settled to `ERROR`
 *     (via `PublishService::abandonGroup()`/`finalizeGroupState()`, from
 *     EITHER the original publish attempt or a scan-triggered
 *     redispatch — this class does not care which), emailing the author
 *     exactly once.
 *
 * ============================================================
 * Why this class exists — the two holes `PublishService::
 * lockGroupIfQueued()`'s docblock documents
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
 * Distinguishing "genuinely orphaned" from "mid-retry" — and the
 * guarantee this makes about never running alongside a live job
 * ============================================================
 *
 * `PublishPostJob::$backoff` is `[60, 300, 900]` across 4 tries: a group
 * legitimately retrying can sit at `QUEUE`, past its `publish_date`, for
 * up to roughly 21 minutes (the sum of every backoff gap plus real
 * queue/HTTP latency) before its FOURTH attempt either succeeds or
 * exhausts into `abandonGroup()`. A scanner that re-dispatches purely
 * off "`QUEUE` and 15 minutes overdue" would routinely fire on a group
 * still legitimately inside that window — not merely redundant, but
 * fighting the retry/backoff mechanism Task 30 built.
 *
 * `PublishPostJob implements ShouldBeUnique`, keyed on the group id.
 * `CallQueuedHandler` releases that lock on a successful `handle()`
 * return, or the instant the worker detects the FINAL attempt has been
 * exceeded — `CallQueuedHandler::failed()` calls
 * `ensureUniqueJobLockIsReleased()` as its very FIRST step, strictly
 * BEFORE it ever invokes `$command->failed()` (verified directly
 * against the framework source: `Illuminate\Queue\CallQueuedHandler::
 * failed()`, not assumed) — never merely on a `release()`/redelivery
 * cycle in between (see `PublishPostJob`'s docblock, "Retry semantics"
 * section). So for as long as a job is genuinely between attempts
 * (released, waiting out its backoff, about to be redelivered), the
 * exact same `Cache` lock `Illuminate\Bus\UniqueLock`/
 * `PendingDispatch::shouldDispatch()` would check before pushing a new
 * dispatch is ALSO held. `redispatchIfOrphaned()` below probes that
 * SAME lock key (`UniqueLock::getKey()`) directly, before ever calling
 * `PublishPostJob::dispatch()`:
 *
 *  - Lock already held -> a job is genuinely still in flight for this
 *    group. This method returns without doing anything at all: no
 *    dispatch attempt, no warning logged, nothing. Proven by
 *    `MissedPostsTest`'s "does not touch a group that is legitimately
 *    mid-retry" case, which holds the real lock exactly the way a live
 *    dispatch would (same pattern as `TokenRefreshTest`'s "never even
 *    queues a second refresh job..." case) and asserts NOTHING was
 *    pushed and NOTHING was logged.
 *  - Lock acquired by our own probe -> no job is currently in flight;
 *    this group really is orphaned. The probe's own lock is released
 *    immediately (never held past this one check), and the real
 *    dispatch below acquires its own, independently.
 *
 * Post-review correction: an earlier revision of this docblock claimed
 * the lock was held "for the entire time the job can be in flight,
 * success or `failed()`" — wrong. It is released BEFORE `failed()`
 * runs, which opens exactly the window described next.
 *
 * What this GUARANTEES, precisely: this method can never be the cause
 * of two `PublishPostJob` instances running `publishGroup()`
 * concurrently for the same group, because the actual decision of
 * whether a second instance is ever allowed onto the queue is made
 * atomically by `PublishPostJob::dispatch()`'s own `UniqueLock::acquire()`
 * call, not by this probe — the probe only decides whether this method
 * BOTHERS to call `dispatch()` at all, purely to avoid noise (a redundant
 * dispatch attempt that would be silently skipped and logged by
 * `LogUniqueJobSkipped` anyway) and to honour the retry/backoff window.
 * Three windows can still let this class's own `dispatch()` reach the
 * queue while something else is concurrently touching the group, none
 * of which ever produces a duplicate PUBLISH, for two DIFFERENT reasons
 * depending on which window:
 *
 *  1. A narrow race between this probe's release and the real
 *     `dispatch()` call (e.g. a customer clicking "retry" in between),
 *     or a reschedule force-releasing the lock right after this
 *     method's own dispatch acquired it — briefly two `PublishPostJob`
 *     instances on the queue, POSSIBLY carrying two DIFFERENT
 *     `queue_version` values (this method's own stale one, and the
 *     reschedule/retry's freshly-bumped one). Here
 *     `PublishService::lockGroupIfQueued()`'s `queue_version` check
 *     (Task 31) — compared under the group's own row lock — is what
 *     saves it: the job carrying the SUPERSEDED version is a pure no-op
 *     the instant it runs.
 *  2. The `failed()` window itself: the lock is gone (see above) but
 *     `abandonGroup()` has not yet run/committed, so this method's
 *     probe can find the lock free and dispatch a fresh job carrying
 *     the group's CURRENT (unbumped, still-matching) `queue_version` —
 *     racing the ORIGINAL job's own `failed()`→`abandonGroup()` call,
 *     which carries the IDENTICAL version. `queue_version` contributes
 *     NOTHING here — both sides match it. What actually saves this
 *     window is `PublishService::lockGroupIfQueued()`'s and
 *     `abandonGroup()`'s shared group-row `lockForUpdate()` PLUS Task
 *     30's `state = QUEUE` predicate on the re-lock: whichever
 *     transaction commits first (a fresh publish attempt succeeding, or
 *     `abandonGroup()` marking the group `ERROR`) is the one that
 *     sticks, and the other — reading the row fresh after waiting on
 *     the same lock — finds `state` no longer `QUEUE` (either
 *     `PUBLISHED` or `ERROR`) and no-ops. Neither path ever lets both
 *     sides act.
 *  3. Two same-version jobs somehow both reaching `publishChannel()` —
 *     `publishChannel()`'s per-row `lockForUpdate()`/`release_id`
 *     re-check (Task 30) is the independent, lowest layer that stops a
 *     duplicate provider call even then.
 *
 * This class relies on those layers for CORRECTNESS and uses the lock
 * probe purely for QUALITY (no needless redispatch, no log
 * noise) — see `PublishService`'s class docblock for the full
 * idempotency accounting those layers are part of.
 *
 * `redispatchIfOrphaned()` deliberately does NOT bump `queue_version`:
 * this is not a reschedule, and the group's schedule generation has not
 * changed — it reads `$group->queue_version` as-is and hands that exact
 * value to the fresh `PublishPostJob`, the same way
 * `PostGroupService::dispatchPublishIfQueued()` does for every other
 * caller. A stale value would simply make the redispatch a guaranteed
 * no-op (harmless — see `lockGroupIfQueued()`'s own docblock on why a
 * stale-version job "no-ops rather than being dangerous"), never a
 * duplicate-publish risk either way.
 */
class MissedPostsService
{
    /**
     * How far past `publish_date` a `QUEUE` group must be before this
     * class even considers it a candidate for redispatch. Deliberately
     * larger than any single `PublishPostJob` backoff gap (max: 900s =
     * 15 minutes) so a group is never even LOOKED at while a first- or
     * second-attempt retry could still plausibly be pending — the lock
     * probe above is the mechanism that makes this class-safe for the
     * THIRD/FOURTH attempt's longer windows too, but there is no reason
     * to probe a group that is barely overdue at all.
     */
    private const int OVERDUE_MINUTES = 15;

    /**
     * Bounded batch size for both passes below — this runs unattended,
     * every 10 minutes, forever; an unbounded `get()` would let one
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
     * Every `QUEUE` group across every tenant whose `publish_date` is
     * more than `OVERDUE_MINUTES` in the past. `chunkById()`, not
     * `get()`/`cursor()` — see this class's docblock ("bounded batch
     * size"). Ordered implicitly by `chunkById()`'s own primary-key
     * cursor, which is all this needs: there is no priority ordering
     * requirement here, only "eventually get to every candidate row".
     */
    private function redispatchOrphaned(): void
    {
        PostGroup::withoutGlobalScope('organization')
            ->where('state', PostState::QUEUE->value)
            ->where('publish_date', '<', now()->subMinutes(self::OVERDUE_MINUTES))
            ->chunkById(self::CHUNK_SIZE, function (Collection $groups): void {
                foreach ($groups as $group) {
                    $this->redispatchIfOrphaned($group);
                }
            });
    }

    /**
     * See this class's docblock for the full reasoning — probes
     * `PublishPostJob`'s own `ShouldBeUnique` lock before ever calling
     * `dispatch()`.
     *
     * Two outcomes once the probe finds the lock held (post-review
     * addition, finding 3):
     *
     *  - `lockCouldStillBeLegitimate()` says yes -> genuinely mid-retry,
     *    left alone entirely: no dispatch, no log, nothing.
     *  - It says no -> the lock has OUTLIVED every window in which a
     *    legitimate attempt for this exact schedule generation could
     *    still hold it. This is a LEAK — a hard-killed worker (SIGKILL
     *    mid-`handle()` never runs `CallQueuedHandler`'s release path),
     *    or a swallowed dispatch that never got as far as running at
     *    all — not evidence of health. Left alone, this group would sit
     *    behind that leaked lock, silently re-skipped on every 10-minute
     *    scan, for up to the job's own `$uniqueFor` (3600s = up to an
     *    hour) — the "declines to act on precisely the state it exists
     *    to fix" gap the review flagged. Logged at `error` (not
     *    `warning`) specifically so this is distinguishable from an
     *    ordinary recovery, and the lock is force-released (matching
     *    `PostGroupService::dispatchPublishIfQueued(forceRedispatch:
     *    true)`'s own `UniqueLock::release()` call) before dispatching,
     *    rather than merely logging and leaving the leak for the NEXT
     *    scan to rediscover.
     *
     * Logging happens AFTER `dispatch()`, not before (review finding 5,
     * minor) — logging first left a log line asserting a redispatch had
     * happened in the one case it could NOT be trusted to have (the
     * dispatch itself being silently swallowed by `LogUniqueJobSkipped`'s
     * own race window — see this class's docblock).
     */
    private function redispatchIfOrphaned(PostGroup $group): void
    {
        $job = new PublishPostJob($group->id, $group->queue_version);
        $lockKey = UniqueLock::getKey($job);

        // A short TTL: this is a probe, not a real reservation — held
        // only long enough to observe whether anything else currently
        // holds it, then released immediately on the next line either
        // way (get() with no owner conflict acquires it; the explicit
        // release() below gives it straight back).
        $probe = Cache::lock($lockKey, 10);

        if ($probe->get()) {
            $probe->release();
            $this->dispatchRedispatch($group, 'missed_posts.redispatched');

            return;
        }

        if ($this->lockCouldStillBeLegitimate($group, $job)) {
            // Something else — a still-retrying PublishPostJob attempt —
            // plausibly holds this lock. Genuinely mid-retry, not
            // orphaned: leave it alone entirely.
            return;
        }

        Log::error('missed_posts.leaked_lock', [
            'post_group_id' => $group->id,
            'queue_version' => $group->queue_version,
            'publish_date' => $group->publish_date?->toIso8601String(),
            'unique_for' => $job->uniqueFor,
        ]);

        app(UniqueLock::class)->release($job);

        $this->dispatchRedispatch($group, 'missed_posts.leaked_lock_redispatched');
    }

    /**
     * True iff a lock still held for `$group`'s `PublishPostJob` could
     * plausibly belong to a LEGITIMATE attempt for the group's CURRENT
     * schedule generation, rather than a leak — see
     * `redispatchIfOrphaned()`'s docblock.
     *
     * `PublishPostJob`'s `ShouldBeUnique` lock is acquired at DISPATCH
     * time with a fixed TTL (`$uniqueFor`), independent of `->delay()`
     * (see `PostGroupService::dispatchPublishIfQueued()`'s docblock) —
     * so the absolute latest moment a lock belonging to a dispatch for
     * THIS group's current `publish_date` could still be alive is
     * `publish_date + $job->uniqueFor` (the best case: the dispatch call
     * happened AT `publish_date`, i.e. an immediate/near-term
     * dispatch — every other case, including the common "scheduled well
     * ahead of time and never touched again" one, only expires EARLIER
     * than that, never later). Once `now()` passes that point, a
     * currently-held lock cannot belong to a still-legitimately-running
     * attempt for this schedule generation, full stop.
     *
     * This bound is deliberately conservative in the OTHER direction: it
     * can still read "could be legitimate" for a lock that has, in
     * fact, already naturally expired well before `publish_date` (true
     * for any post originally scheduled more than `$uniqueFor` ahead of
     * its own due date, which never gets a fresh dispatch/lock unless
     * it is rescheduled or retried) — in that case this class's PROBE
     * itself would already have found the lock absent and taken the
     * "genuinely orphaned" branch above, so this method is never even
     * consulted for it. This method only runs at all once the probe
     * finds a lock PRESENT, so it only ever needs to decide "is this
     * presence still plausible", never "should a lock exist here".
     */
    private function lockCouldStillBeLegitimate(PostGroup $group, PublishPostJob $job): bool
    {
        if ($group->publish_date === null) {
            // Defensive only: redispatchOrphaned()'s own query already
            // requires publish_date < now() - OVERDUE_MINUTES, which a
            // null column can never satisfy, so this branch is
            // unreachable via that caller. Treated as "could still be
            // legitimate" (never force-release) rather than guessing.
            return true;
        }

        return $group->publish_date->isAfter(now()->subSeconds($job->uniqueFor));
    }

    private function dispatchRedispatch(PostGroup $group, string $logMessage): void
    {
        PublishPostJob::dispatch($group->id, $group->queue_version);

        Log::warning($logMessage, [
            'post_group_id' => $group->id,
            'queue_version' => $group->queue_version,
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
     * brief — `abandonGroup()` already owns the QUEUE->ERROR transition
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
     * The claim step is a single conditional `UPDATE ... WHERE
     * notified_at IS NULL`, not a read-then-`save()` — post-review fix
     * (finding 2). `ScanMissedPostsJob implements ShouldBeUnique` now
     * makes two full scan EXECUTIONS overlapping unlikely, but that lock
     * is a second layer, not the only one this method should lean on
     * (see that job's and routes/console.php's docblocks for why
     * `withoutOverlapping()`/`onOneServer()` alone do not cover a
     * `Schedule::job()` push the way they cover a `Schedule::command()`
     * run). A plain `UPDATE`'s `WHERE` clause is re-evaluated by
     * Postgres against the row's current, post-lock-wait value before
     * the write applies — the standard guarantee every `UPDATE`
     * statement gives against a concurrent writer, independent of any
     * scheduler-level mutex — so of two overlapping calls racing on the
     * SAME group, only the one that is still first to see `notified_at
     * IS NULL` at the moment its `UPDATE` actually executes affects a
     * row; the other affects zero rows and must not send anything.
     */
    private function notifyOne(PostGroup $group): void
    {
        $error = $group->error ?? $this->buildErrorSummary($group);

        $claimed = PostGroup::withoutGlobalScope('organization')
            ->whereKey($group->id)
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
     * message `Post::$error`/`abandonGroup()`'s `$reason` carries
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
