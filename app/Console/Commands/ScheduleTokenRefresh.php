<?php

namespace App\Console\Commands;

use App\Jobs\RefreshIntegrationTokenJob;
use App\Services\Integrations\TokenRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * The scheduled entry point for Task 16's token-refresh sweep — see
 * routes/console.php for its `->hourly()` registration. Runs with NO
 * OrganizationContext set (a scheduled command is not a request, nothing
 * resolves a tenant for it), which is exactly why all the cross-tenant
 * reasoning lives in TokenRefreshService::dueForRefresh() rather than
 * here: this command does nothing but ask that method "what's due" and
 * dispatch one job per row. See that method's docblock for why a naive
 * `Integration::where(...)` would silently find nothing forever instead.
 *
 * Dispatches one RefreshIntegrationTokenJob per due integration rather
 * than one job for the whole batch, so a single slow/broken channel's
 * refresh can never block or delay another channel's — and so
 * RefreshIntegrationTokenJob's ShouldBeUnique can dedupe per channel
 * instead of needing to reason about a shared batch job.
 */
class ScheduleTokenRefresh extends Command
{
    protected $signature = 'integrations:refresh-tokens';

    protected $description = 'Dispatch a refresh job for every integration whose token expires within the configured proactive window (see config(\'publishing.token_refresh_window_days\')).';

    public function handle(TokenRefreshService $service): int
    {
        $due = $service->dueForRefresh(Date::now());

        foreach ($due as $integration) {
            RefreshIntegrationTokenJob::dispatch($integration);
        }

        $this->info("Dispatched {$due->count()} token refresh job(s).");

        return self::SUCCESS;
    }
}
