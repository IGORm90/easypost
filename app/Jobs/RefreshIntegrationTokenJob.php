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
 *
 * Retry semantics under this project's queue driver (QUEUE_CONNECTION=redis)
 * -- corrected after review, since this docblock is the pattern Tasks
 * 30-33 are expected to copy: release() does NOT get a fresh attempt
 * budget. Illuminate\Queue\LuaScripts::pop() increments
 * reserved['attempts'] on every pop, including a redelivery after
 * release() (DatabaseQueue::markJobAsReserved() does the same for the
 * database driver) -- verified directly against the framework source,
 * not assumed. So a ProviderRateLimitException handled via release()
 * below DOES count against $tries exactly like an uncaught exception
 * would; enough consecutive rate-limited pops exhausts retries and the
 * job lands in failed_jobs, not a silent infinite wait.
 *
 * Deliberate decision for THIS job: that outcome is acceptable here,
 * which is why $tries below is a modest bounded number rather than
 * something crafted to survive an arbitrarily long rate-limit window.
 * Two things make it safe specifically for token refresh: (1)
 * dueForRefresh() fires 2 hours ahead of actual token expiry, and (2)
 * ScheduleTokenRefresh re-enumerates and dispatches a brand-new job
 * (fresh attempt counter) every hour regardless of whether a previous
 * job for the same integration succeeded, failed, or died into
 * failed_jobs. So even a worst-case "every attempt hits the rate limit"
 * run self-heals on the next hourly pass, well inside that 2-hour
 * runway -- a failed_jobs entry here costs nothing customer-visible.
 *
 * This reasoning does NOT transfer to Task 30's publish job. A queued
 * post has no such 2-hour safety net and no hourly re-enumeration
 * behind it -- exhausting $tries mid-rate-limit-window there means a
 * post that would have succeeded once the provider's own Retry-After
 * elapsed fails instead, for no reason the customer caused. That job
 * must NOT copy release($e->retryAfterSeconds) under the assumption
 * used in an earlier draft of this docblock (that it "doesn't consume
 * $tries" -- it does). It needs its own deliberate choice: e.g. a
 * $tries budget sized to the provider's realistic rate-limit window, an
 * explicit retryUntil() deadline (which Laravel checks INSTEAD of
 * $tries when defined -- see Worker::markJobAsFailedIfWillExceedMaxAttempts()),
 * or delete() immediately followed by a fresh, delayed dispatch() to
 * reset the attempt counter rather than releasing the same reservation.
 */
class RefreshIntegrationTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shared budget across BOTH ProviderTransientException (automatic
     * retry via backoff(), below) and ProviderRateLimitException
     * (explicit release() in handle()) -- see the class docblock's
     * "Retry semantics" section for why both consume the same counter
     * on this project's redis queue, and why exhausting it here is an
     * accepted, self-healing outcome rather than a bug.
     */
    public int $tries = 8;

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
     * Governs the automatic retry delay after an uncaught
     * ProviderTransientException only -- a ProviderRateLimitException's
     * delay comes from the provider's own Retry-After via release()
     * in handle(), not from this array. Sized to $tries = 8; Laravel
     * reuses the last entry for any attempt beyond the array's length.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 300, 900, 1800, 3600, 3600, 3600];
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
            // Waits exactly as long as the provider asked, then lets the
            // queue re-attempt -- but this DOES consume one of $tries on
            // this project's redis queue (attempts increment on every
            // pop, release included). See the class docblock's "Retry
            // semantics" section for why that's an accepted, self-healing
            // outcome for THIS job specifically, and why Task 30 must not
            // copy this line under the opposite assumption.
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
