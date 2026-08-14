<?php

namespace App\Jobs;

use App\Services\Posts\MissedPostsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The scheduled entry point for Task 32's safety net — see
 * routes/console.php for its `->everyTenMinutes()` registration. Runs
 * with NO `OrganizationContext` set, exactly like `PublishPostJob` and
 * `RefreshIntegrationTokenJob` (see `AppServiceProvider`'s queue-event
 * listeners, which clear it around every job boundary) — this class
 * does nothing but hand off to `MissedPostsService::scan()`, which is
 * where every cross-tenant reasoning and both passes (redispatch,
 * notify) actually live. See that class's docblock for the full
 * accounting of what this job recovers from and how it stays safe
 * against ever running a `PublishPostJob` alongside one already in
 * flight.
 *
 * Deliberately thin, matching `PublishPostJob`'s own shape: all
 * business logic lives in the service, this job is only the
 * queue/schedule boundary.
 *
 * `ShouldBeUnique` — added post-review (finding 2). `routes/console.php`
 * registers this via `Schedule::job()`, not `Schedule::command()`:
 * `Schedule::job()` builds a `CallbackEvent` whose mutex only spans the
 * PUSH onto the queue, not this job's actual execution — unlike
 * `ScheduleTokenRefresh`'s `Event`, whose `withoutOverlapping()` mutex
 * covers its whole run. So without a lock of this job's OWN, two
 * scheduler ticks could each push cleanly (their pushes never overlap),
 * yet still end up executing concurrently on two different workers (a
 * slow first run still mid-`chunkById()` when the second tick's push is
 * picked up) — and `MissedPostsService::notifyFailed()`'s
 * `whereNull('notified_at')` read is not by itself atomic against that.
 * `$uniqueFor` below is deliberately shorter than the `everyTenMinutes()`
 * schedule interval, so a normal (fast) run's lock is always gone well
 * before the next tick's push, and a wedged run's lock cannot survive
 * past a small buffer before the FOLLOWING tick after that — the same
 * "self-heals within roughly one more interval" trade-off
 * `RefreshIntegrationTokenJob`'s docblock documents for its own
 * uniqueFor()/schedule relationship.
 *
 * `uniqueId()` is a constant, not derived from any argument — this job
 * takes none; there is exactly one logical "the scan" per moment in
 * time, never a per-entity instance the way `PublishPostJob`/
 * `RefreshIntegrationTokenJob` are keyed per group/integration.
 *
 * This lock is a second, execution-level layer, not a replacement for
 * `MissedPostsService::notifyOne()`'s own conditional `UPDATE ... WHERE
 * notified_at IS NULL` — that claim is what actually makes a single
 * group's notification exactly-once even if this lock is ever bypassed
 * (e.g. a future caller invokes `MissedPostsService::scan()` directly,
 * outside this job).
 */
final class ScanMissedPostsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Comfortably under the 600s `everyTenMinutes()` schedule interval —
     * see the class docblock.
     */
    public int $uniqueFor = 570;

    public function uniqueId(): string
    {
        return 'scan-missed-posts';
    }

    public function handle(MissedPostsService $service): void
    {
        $service->scan();
    }
}
