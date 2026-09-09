<?php

namespace App\Console\Commands;

use App\Services\Posts\DueDispatchService;
use Illuminate\Console\Command;

/**
 * The scheduled entry point for the durable-scheduling claim step — see
 * `routes/console.php` for its `->everyMinute()` registration and
 * `DueDispatchService` for the atomic claim query itself.
 *
 * Runs with NO `OrganizationContext` set, matching `ScheduleTokenRefresh`
 * and `ScanMissedPostsJob`: a scheduled command is not a request, nothing
 * resolves a tenant for it, and the claim is deliberately cross-tenant.
 *
 * `Schedule::command()`, not `Schedule::job()`, in `routes/console.php`:
 * that file's own comments on `ScanMissedPostsJob`'s registration explain
 * at length that `Schedule::job()`'s `withoutOverlapping()`/`onOneServer()`
 * mutex only covers the instant a job is PUSHED, not its execution. Here
 * the whole claim pass — every batch, until the loop breaks — must be
 * covered, which is exactly what `Schedule::command()`'s `Event` mutex
 * (spanning the command's full run) gives.
 */
class DispatchDuePostsCommand extends Command
{
    protected $signature = 'posts:dispatch-due';

    protected $description = 'Claim every scheduled post due within the lookahead window and dispatch a publish job for each.';

    public function handle(DueDispatchService $service): int
    {
        $claimed = $service->dispatchDue();

        $this->info("Claimed {$claimed} due post group(s).");

        return self::SUCCESS;
    }
}
