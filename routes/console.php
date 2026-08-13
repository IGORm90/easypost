<?php

use App\Console\Commands\ScheduleTokenRefresh;
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
