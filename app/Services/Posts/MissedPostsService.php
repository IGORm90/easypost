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
 * `PublishPostJob implements ShouldBeUnique`, keyed on the group id, for
 * the ENTIRE time it can be in flight: `CallQueuedHandler` only releases
 * that lock once the job finishes for good (success, or `failed()` after
 * `$tries` is exhausted) — never merely on a `release()`/redelivery
 * cycle (see that job's docblock, "Retry semantics" section, and
 * `Illuminate\Queue\CallQueuedHandler::call()`, verified directly against
 * the framework source). So for as long as a job is genuinely retrying,
 * the exact same `Cache` lock `Illuminate\Bus\UniqueLock`/
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
 * What this GUARANTEES, precisely: this method can never be the cause
 * of two `PublishPostJob` instances running `publishGroup()`
 * concurrently for the same group, because the actual decision of
 * whether a second instance is ever allowed onto the queue is made
 * atomically by `PublishPostJob::dispatch()`'s own `UniqueLock::acquire()`
 * call, not by this probe — the probe only decides whether this method
 * BOTHERS to call `dispatch()` at all, purely to avoid noise (a redundant
 * dispatch attempt that would be silently skipped and logged by
 * `LogUniqueJobSkipped` anyway) and to honour the retry/backoff window.
 * There IS a narrow race between this probe's release and the real
 * `dispatch()` call (e.g. a customer clicking "retry" in between) where
 * `dispatch()` could still be silently skipped, or — in the reverse
 * case, a reschedule force-releasing the lock right after this method's
 * own dispatch acquired it — briefly put TWO `PublishPostJob` instances
 * on the queue for the same group. Neither ever produces a duplicate
 * PUBLISH: `PublishService::lockGroupIfQueued()`'s `queue_version` check
 * (Task 31) — compared under the group's own row lock — makes any job
 * carrying a superseded version a pure no-op the instant it runs, and
 * `publishChannel()`'s per-row `lockForUpdate()`/`release_id` re-check
 * (Task 30) is the independent, lower layer that stops a duplicate
 * provider call even if two same-version jobs somehow both got that
 * far. This class relies on those two layers for CORRECTNESS and uses
 * the lock probe purely for QUALITY (no needless redispatch, no log
 * noise) — see `PublishService`'s class docblock for the full
 * idempotency accounting those two layers are part of.
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
     * `dispatch()`, and returns silently (no dispatch, no log) if a live
     * job already holds it.
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

        if (! $probe->get()) {
            // Something else — a still-retrying PublishPostJob attempt —
            // already holds this lock. Genuinely mid-retry, not
            // orphaned: leave it alone entirely.
            return;
        }

        $probe->release();

        Log::warning('missed_posts.redispatched', [
            'post_group_id' => $group->id,
            'queue_version' => $group->queue_version,
            'publish_date' => $group->publish_date?->toIso8601String(),
        ]);

        PublishPostJob::dispatch($group->id, $group->queue_version);
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
     */
    private function notifyOne(PostGroup $group): void
    {
        $group->error ??= $this->buildErrorSummary($group);
        $group->notified_at = now();
        $group->save();

        if ($group->creator !== null) {
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
