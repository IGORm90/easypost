<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Notifications\IntegrationBrokenNotification;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\ProviderRegistry;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owns the "keep OAuth tokens alive" half of Task 16: which integrations
 * are due for a refresh, and what happens to one when its refresh is
 * attempted. The retry/backoff policy for transient failures lives in
 * RefreshIntegrationTokenJob, not here — this class only ever sees a
 * refresh through to "succeeded" or "credentials are dead", and lets any
 * ProviderTransientException / ProviderRateLimitException propagate
 * untouched to the job.
 *
 * Cross-tenant by design: dueForRefresh() is the ONE place in this
 * codebase that is expected to see every organization's integrations at
 * once — it backs an hourly scheduled command, which runs with no
 * OrganizationContext set (see AppServiceProvider's queue-event
 * listeners, which clear it around every job boundary, and
 * BelongsToOrganization's global scope, which fails closed to zero rows
 * when no context is set). A plain `Integration::where(...)` here would
 * therefore silently find nothing, forever. `withoutGlobalScope('organization')`
 * below is that deliberate, explicit opt-out — never remove it, and
 * never copy this pattern into any other query in this codebase without
 * the same justification.
 */
class TokenRefreshService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * Every non-disabled, non-trashed integration whose token expires at
     * or before $asOf + config('publishing.token_refresh_window_days'),
     * restricted to providers that implement SupportsRefresh.
     * Deliberately excludes:
     *  - disabled integrations (a broken channel already flagged
     *    refresh_needed/disabled must not be retried here — the user has
     *    to reconnect it; see IntegrationBrokenNotification);
     *  - soft-deleted integrations (SoftDeletes' own global scope still
     *    applies — only the 'organization' scope is removed above);
     *  - integrations with no known expiry (token_expires_at null means
     *    "does not expire" or "unknown", per AuthResultDto's docblock,
     *    never "due now");
     *  - providers that don't implement SupportsRefresh at all (e.g.
     *    Telegram's manual bot token) — checked via instanceof, never a
     *    provider-name comparison, per this project's "no provider
     *    logic outside app/Social/Providers" rule.
     *
     * @return Collection<int, Integration>
     */
    public function dueForRefresh(CarbonInterface $asOf): Collection
    {
        $refreshableIdentifiers = $this->refreshableProviderIdentifiers();

        if ($refreshableIdentifiers === []) {
            return new Collection;
        }

        return Integration::withoutGlobalScope('organization')
            ->where('disabled', false)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $asOf->clone()->addDays((int) config('publishing.token_refresh_window_days')))
            ->whereIn('provider_identifier', $refreshableIdentifiers)
            ->get();
    }

    /**
     * Attempts to refresh one integration's token in place.
     *
     * Success: new token/refresh_token/token_expires_at persisted,
     * refresh_needed cleared.
     *
     * ProviderAuthException (the refresh token itself is dead): the
     * integration is marked disabled + refresh_needed so the publish
     * pipeline (Task 30) and the scheduler (via dueForRefresh()'s
     * `disabled = false` filter) both stop touching it, and the
     * organization's owner is notified — but only the first time. If the
     * integration was already disabled when this ran, the fields are
     * re-applied (idempotent) but IntegrationBrokenNotification is NOT
     * sent again: without this guard, a channel that stays broken for
     * days would be picked up by the pipeline/manual retries repeatedly
     * and re-notify the owner every time, which is the "must not spam"
     * requirement this method exists to satisfy.
     *
     * Any other exception (ProviderTransientException,
     * ProviderRateLimitException, ...) propagates untouched — this
     * method makes no state change for those, on purpose: a network
     * blip or a rate limit is not evidence the credential is bad, and
     * marking a healthy channel broken over one is the exact failure
     * mode Task 16 calls out as customer-visible. The caller
     * (RefreshIntegrationTokenJob) decides the retry policy for those.
     *
     * QUEUE.md Task 8: wrapped in a per-integration Cache::lock, the same
     * primitive MissedPostsService and OAuthStateService::consume() already
     * use elsewhere in this codebase. This is a real correctness fix, not
     * defensive belt-and-braces: several providers rotate single-use
     * refresh tokens, so two genuinely concurrent refreshes for the SAME
     * integration mean the second one presents an already-consumed
     * refresh token and the provider revokes the whole grant — permanently
     * disconnecting the channel. RefreshIntegrationTokenJob's own
     * ShouldBeUnique lock already stops a second dispatch from ever
     * reaching the queue in the common case, but this method is called
     * directly in tests and could in principle be reached by any future
     * caller that bypasses the job — the lock here makes the guarantee
     * belong to the operation itself, not to one caller's dispatch path.
     *
     * block(5, ...), not a bare get(): a refresh that's already in flight
     * for this integration should make a second, overlapping call wait
     * briefly and then skip — not fail loudly. 5s comfortably covers a
     * real provider round-trip without stalling the caller for long. A
     * failed block() means the first call is still running (or hung);
     * either way there is nothing for this call to do, so a
     * LockTimeoutException is caught and treated as "someone else is
     * already refreshing this." Missing this particular pass is harmless:
     * dueForRefresh()'s multi-day window means the integration is picked
     * up again on the very next scheduled sweep.
     *
     * Post-review fix (round 4): $integration is re-read with fresh()
     * ONLY AFTER the lock is actually held, never before. The lock alone
     * does not protect against staleness — it only serializes WHEN two
     * calls run, not WHICH data they act on. A caller can queue up
     * waiting on block() while holding an $integration instance loaded
     * before another call rotated this integration's refresh_token and
     * committed; if that stale instance were used once the wait finally
     * ends, several providers (see BlueskyProvider::refresh()) would
     * present an already-consumed, single-use refresh token and get the
     * whole grant revoked — precisely the harm this lock exists to
     * prevent. Re-reading here, inside the acquired lock, is also what
     * makes markBroken()'s own anti-spam guard correct under contention:
     * that guard reads $integration->disabled off whatever instance it's
     * handed, so a stale copy would see disabled=false even after another
     * call already flipped it, and would re-notify the owner. fresh()
     * uses newQueryWithoutScopes() (see Integration's BelongsToOrganization),
     * so this works with no OrganizationContext set exactly like the
     * scheduler's own call path; it still respects SoftDeletes, so a row
     * deleted while we waited comes back null and is treated as nothing
     * left to do rather than an exception.
     */
    public function refresh(Integration $integration): void
    {
        try {
            Cache::lock("token-refresh:{$integration->id}", 30)
                ->block(5, function () use ($integration): void {
                    $current = $integration->fresh();

                    if ($current === null) {
                        return;
                    }

                    $this->refreshLocked($current);
                });
        } catch (LockTimeoutException) {
            return;
        }
    }

    private function refreshLocked(Integration $integration): void
    {
        $provider = $this->providers->get($integration->provider_identifier);

        if (! $provider instanceof SupportsRefresh) {
            // Defensive only: dueForRefresh() already filters by
            // capability, so a well-behaved caller never reaches this.
            // A job dispatched against a stale/inconsistent integration
            // (provider config changed between enumeration and
            // execution) must still fail safe rather than call a method
            // the provider doesn't have.
            return;
        }

        try {
            $auth = $provider->refresh($integration);
        } catch (ProviderAuthException) {
            $this->markBroken($integration);

            return;
        }

        DB::transaction(function () use ($integration, $auth): void {
            $integration->token = $auth->token;
            $integration->refresh_token = $auth->refreshToken ?? $integration->refresh_token;
            $integration->token_expires_at = $auth->expiresAt;
            $integration->refresh_needed = false;
            $integration->save();
        });
    }

    /**
     * @return list<string>
     */
    private function refreshableProviderIdentifiers(): array
    {
        $identifiers = [];

        foreach ($this->providers->all() as $identifier => $provider) {
            if ($provider instanceof SupportsRefresh) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    private function markBroken(Integration $integration): void
    {
        $alreadyBroken = $integration->disabled;

        $integration->refresh_needed = true;
        $integration->disabled = true;
        $integration->save();

        if ($alreadyBroken) {
            return;
        }

        // withoutGlobalScope('organization') is not needed here:
        // Organization itself carries no BelongsToOrganization scope
        // (it IS the tenant, not a tenant-owned row) — only owner()
        // (a plain BelongsTo onto User) is being traversed.
        $owner = $integration->organization?->owner;

        $owner?->notify(new IntegrationBrokenNotification($integration));
    }
}
