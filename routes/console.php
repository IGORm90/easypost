<?php

use App\Console\Commands\ScheduleTokenRefresh;
use App\Jobs\ScanMissedPostsJob;
use App\Jobs\SendDigestEmailJob;
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
 * withoutOverlapping()/onOneServer() here are WEAKER than they look, and
 * weaker than ScheduleTokenRefresh's use of the same two methods above —
 * post-review correction (finding 2). `Schedule::command()` registers an
 * `Event` whose mutex spans the ENTIRE command run, start to finish.
 * `Schedule::job()` registers a `CallbackEvent` whose callback only
 * PUSHES the job onto the queue — the mutex (and onOneServer()'s
 * cross-server lock) is released the instant that push returns, not
 * when ScanMissedPostsJob::handle() finishes. So two scheduler ticks 10
 * minutes apart are still prevented from double-pushing, but nothing
 * here stops a genuinely slow scan from still being mid-run on a worker
 * when the NEXT push lands, or from two independently-pushed executions
 * overlapping after a worker restart re-delivers one. The actual overlap
 * guard for the scan's own execution is `ScanMissedPostsJob implements
 * ShouldBeUnique` (see that class) — a real `Cache` lock held for the
 * job's execution window, not merely its dispatch. `withoutOverlapping()`/
 * `onOneServer()` are kept here as a second, cheap layer against
 * needlessly re-pushing while a push is already in flight, not as the
 * thing that makes concurrent scans safe.
 */
Schedule::job(new ScanMissedPostsJob)
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Task 33: the weekly digest. There is no single UTC instant that is
 * "Monday 09:00" for every user — see DigestService's class docblock for
 * the full reasoning — so this runs hourly and DigestService::isDueNow()
 * decides, per user, whether it is currently their local Monday 09:00-09:59
 * hour. Hourly sampling against a 60-minute-wide local window guarantees
 * exactly one tick lands in every user's window every day, regardless of
 * their zone's UTC offset (whole-hour, half-hour, or 45-minute).
 *
 * Schedule::job(), not Schedule::command() — same reasoning as
 * ScanMissedPostsJob above: a slow digest pass never blocks the scheduler
 * process itself. withoutOverlapping()/onOneServer() are the same
 * dispatch-only-level guard documented there (real protection against
 * two overlapping EXECUTIONS is SendDigestEmailJob's own ShouldBeUnique,
 * with DigestService::claim()'s conditional UPDATE as the actual
 * per-user, exactly-once guarantee underneath both).
 */
Schedule::job(new SendDigestEmailJob)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
