<?php

namespace App\Jobs;

use App\Services\Posts\MissedPostsService;
use Illuminate\Bus\Queueable;
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
 */
final class ScanMissedPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MissedPostsService $service): void
    {
        $service->scan();
    }
}
