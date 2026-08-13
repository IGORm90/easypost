<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\Integrations\TokenRefreshService;
use App\Social\Exceptions\ProviderRateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes one Integration's OAuth token. Dispatched by
 * App\Console\Commands\ScheduleTokenRefresh, one job per due integration,
 * never for the whole batch at once — this is what lets ShouldBeUnique
 * (below) dedupe at the per-channel level.
 *
 * Carries the Integration model itself, not a bare id: SerializesModels
 * restores a queued model via Model::newQueryForRestoration(), which
 * explicitly bypasses ALL global scopes — including
 * BelongsToOrganization's 'organization' scope — so rehydrating this on
 * a worker with no OrganizationContext set (the normal state for a
 * queued job; see AppServiceProvider's queue-event listeners) does not
 * hit BelongsToOrganization's "fail closed to zero rows" behaviour the
 * way a query built through the model's normal scoped query builder
 * would. Verified directly, not assumed — see Model::newQueryForRestoration()
 * and Model::fresh(), both of which call newQueryWithoutScopes().
 *
 * Overlap/uniqueness decision: ShouldBeUnique, keyed on the integration's
 * id, for the whole time the job can plausibly be in flight
 * (uniqueFor()). Two RefreshIntegrationTokenJob instances for the SAME
 * integration racing would let the slower one overwrite the faster one's
 * freshly-stored token with an older value — a live channel would start
 * failing every subsequent publish with a token the provider has already
 * invalidated. ShouldBeUnique stops the second instance from ever being
 * pushed onto the queue while the first is still pending/executing,
 * which is a stronger guarantee than WithoutOverlapping middleware (that
 * would still let a second instance sit on the queue and run once the
 * first releases its lock). This is scoped per-integration, not
 * per-command: independent integrations must refresh concurrently, only
 * the same one must serialize. The scheduler command itself additionally
 * uses withoutOverlapping()+onOneServer() (see routes/console.php) so
 * the ENUMERATION step doesn't pile up if it ever gets slow — that's a
 * separate concern from this job's per-channel lock.
 */
class RefreshIntegrationTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounded retries with backoff for ProviderTransientException (left
     * to propagate below — Laravel's queue worker retries automatically
     * up to $tries using backoff()). A ProviderRateLimitException is
     * handled explicitly instead (release() with the provider's own
     * Retry-After), so it does not count against $tries.
     */
    public int $tries = 5;

    public function __construct(
        public readonly Integration $integration,
    ) {}

    public function uniqueId(): string
    {
        return $this->integration->id;
    }

    /**
     * Matches (and slightly exceeds) the hourly schedule cadence: a lock
     * that outlived the job itself but expired before the next hourly
     * dispatch would let that next run silently double-refresh the same
     * channel again — the exact race this job exists to prevent.
     */
    public function uniqueFor(): int
    {
        return 3600;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(TokenRefreshService $service): void
    {
        // newQueryWithoutScopes() under the hood (see class docblock) —
        // safe with no OrganizationContext set. Re-read rather than
        // trust the (possibly stale, since dispatch) constructor copy:
        // the channel may have been disabled, reconnected, or
        // soft-deleted in the time this job sat on the queue.
        $integration = $this->integration->fresh();

        if ($integration === null || $integration->disabled || $integration->trashed()) {
            // Idempotent no-op: already broken and notified, already
            // fixed by a reconnect, or gone. Nothing for this dispatch
            // to do — in particular, never re-run TokenRefreshService::refresh()
            // against an already-disabled integration, which is what
            // would re-trigger IntegrationBrokenNotification.
            return;
        }

        try {
            $service->refresh($integration);
        } catch (ProviderRateLimitException $e) {
            // Not a $tries-consuming failure: wait exactly as long as
            // the provider asked, then let the queue re-attempt.
            $this->release($e->retryAfterSeconds);

            return;
        }

        // Any ProviderTransientException from $service->refresh()
        // deliberately propagates uncaught here: TokenRefreshService
        // makes no state change for it, so letting it surface as a
        // failed job attempt is exactly what triggers this job's own
        // $tries/backoff() retry — never ProviderAuthException, which
        // TokenRefreshService already handles internally (marks the
        // integration broken and notifies) and never rethrows.
    }
}
