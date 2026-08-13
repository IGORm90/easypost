<?php

use App\Console\Commands\ScheduleTokenRefresh;
use App\Jobs\ScanMissedPostsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Task 16, this project's first scheduled background job: sweeps every
 * organization's integrations hourly for tokens expiring within 2 hours
 * and dispatches a refresh job for each (see ScheduleTokenRefresh and
 * TokenRefreshService::dueForRefresh() for why this is deliberately
 * cross-tenant).
 *
 * withoutOverlapping(): the command itself must not start a second
 * enumeration pass while a previous one is still running (e.g. a slow
 * database moment) — that would double-dispatch RefreshIntegrationTokenJob
 * for the same due integrations. This is a distinct guard from that
 * job's own ShouldBeUnique (see its docblock): this one protects the
 * scheduler's dispatch step, ShouldBeUnique protects each individual
 * channel's refresh execution.
 *
 * onOneServer(): the `scheduler` container is a single dedicated
 * instance today, but the app/php container it's built from could be
 * scaled horizontally without anyone remembering to touch this file —
 * onOneServer() makes "only one dispatch pass per hour, however many
 * app servers exist" true by construction rather than by deployment
 * convention.
 */
Schedule::command(ScheduleTokenRefresh::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Task 32: the missed-posts safety net — see MissedPostsService's
 * docblock for the two holes this recovers from (a crash/outage between
 * commit and dispatch, and any ShouldBeUnique swallow that isn't one of
 * PostGroupService's already-hardened reschedule paths). Both leave a
 * group sitting at QUEUE with no live job working it, which is exactly
 * what this scan finds.
 *
 * Schedule::job(), not Schedule::command(): ScanMissedPostsJob is a
 * plain ShouldQueue job (matching PublishPostJob's own shape), not an
 * Artisan command — Schedule::job() pushes it onto the queue at the
 * scheduled tick rather than running it inline in the scheduler
 * process, so a slow scan pass never blocks the scheduler itself.
 *
 * withoutOverlapping()/onOneServer(): identical reasoning to
 * ScheduleTokenRefresh above — a second enumeration pass starting while
 * the previous one is still running would needlessly re-probe the same
 * candidate rows (harmless per MissedPostsService's own lock-probe
 * guarantee, but pure waste), and onOneServer() keeps that true
 * regardless of how many app/php containers this project ever scales
 * to.
 */
Schedule::job(new ScanMissedPostsJob)
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
