<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\Organization;
use App\Social\Dto\AuthResultDto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Owns writes to the `integrations` table: connecting/reconnecting a
 * channel and the disable/enable/soft-delete lifecycle around it.
 *
 * Authorization boundary, by design: `connect()` takes an explicit
 * `Organization $org` rather than reading `OrganizationContext`, because
 * the OAuth callback that will call it (Task 13) runs mid-flow and may
 * not have a fully resolved tenant context the way a normal
 * `organization`-middleware-gated request does. This method trusts that
 * argument completely — it does not re-check that the calling user
 * belongs to $org. That check is the caller's responsibility: the Task 13
 * controller must resolve $org from a source the caller cannot forge
 * (the authenticated user's real membership — e.g. a signed OAuth `state`
 * value minted server-side at the start of the flow, or the same
 * `organization`-middleware-scoped context used elsewhere), never from a
 * raw request parameter. disable()/enable()/softDelete() take an
 * `Integration` instance directly; because `Integration` uses
 * `BelongsToOrganization`, any lookup that produced that instance through
 * the ORM (other than an explicit `withoutGlobalScope('organization')`)
 * was already scoped to the current tenant, so no separate check is
 * needed here.
 */
class IntegrationService
{
    /**
     * Upserts one `integrations` row keyed on
     * `(organization_id, provider_identifier, internal_id)`, scoped
     * explicitly to $org — never to the ambient `OrganizationContext` —
     * so this can only ever read or write rows belonging to $org.
     *
     * Looks up `withTrashed()`: reconnecting a channel that this same
     * organization previously disconnected (soft-deleted) restores and
     * updates that row in place rather than leaving it deleted and
     * inserting a second one. Because the lookup is filtered by
     * `organization_id` first, this can never restore or touch another
     * organization's soft-deleted row — the query simply never sees rows
     * outside $org, deleted or not. A database-level partial unique index
     * (see the `integrations` migration) backs this against a race
     * between two concurrent connect() calls for the same key — see
     * `save()`'s try/catch below for how a lost race is handled rather
     * than left to surface as a raw 500 mid-OAuth-callback.
     */
    public function connect(Organization $org, string $providerIdentifier, AuthResultDto $auth): Integration
    {
        $integration = $this->findUpsertTarget($org, $providerIdentifier, $auth->internalId);

        if (! $integration instanceof Integration) {
            $integration = new Integration([
                'organization_id' => $org->id,
                'provider_identifier' => $providerIdentifier,
                'internal_id' => $auth->internalId,
            ]);
        }

        $integration->deleted_at = null;
        $integration->name = $auth->name;
        $integration->picture = $auth->picture;
        $integration->token = $auth->token;
        $integration->refresh_token = $auth->refreshToken;
        $integration->token_expires_at = $auth->expiresAt;
        $integration->settings = $auth->settings;
        $integration->refresh_needed = false;

        try {
            // Wrapped in its own transaction (a SAVEPOINT when already
            // inside an outer one, e.g. under RefreshDatabase in tests):
            // Postgres aborts the whole enclosing transaction after a
            // failed statement, which would otherwise poison the
            // recovery lookup below with "current transaction is
            // aborted" instead of letting it run.
            DB::transaction(function () use ($integration): void {
                $integration->save();
            });
        } catch (QueryException $e) {
            // Lost the race: our initial lookup ran before a concurrent
            // connect() for the same (org, provider, internal_id) key
            // committed its insert, so ours collided with the partial
            // unique index instead of finding a row to update. That
            // concurrent call already produced the correct, live
            // integration — re-fetch and return it rather than letting
            // this surface as an uncaught QueryException mid-callback.
            // Narrowly matched on the specific index name so an
            // unrelated constraint violation still propagates as a real
            // error instead of being silently swallowed.
            if (! str_contains($e->getMessage(), 'integrations_org_provider_internal_live_unique')) {
                throw $e;
            }

            $winner = $this->findUpsertTarget($org, $providerIdentifier, $auth->internalId);

            if (! $winner instanceof Integration) {
                throw $e;
            }

            return $winner;
        }

        return $integration;
    }

    /**
     * Updates display/organizational metadata (never `token`,
     * `refresh_token`, or anything OAuth-identity-related — those are
     * only ever written by `connect()`/`SupportsRefresh::refresh()`) and,
     * when `time_slots` is present, replaces the integration's weekly
     * posting slots wholesale. `$data` is expected pre-validated by
     * `UpdateIntegrationRequest` (range/uniqueness of `time_slots`
     * already enforced there).
     *
     * @param  array{name?: string, group_name?: ?string, customer_name?: ?string, settings?: ?array<string, mixed>, time_slots?: list<int>}  $data
     */
    public function update(Integration $integration, array $data): Integration
    {
        DB::transaction(function () use ($integration, $data): void {
            $integration->fill(Arr::only($data, ['name', 'group_name', 'customer_name', 'settings']));
            $integration->save();

            if (array_key_exists('time_slots', $data)) {
                $integration->timeSlots()->delete();

                foreach ($data['time_slots'] as $minutes) {
                    $integration->timeSlots()->create(['minutes_from_week_start' => $minutes]);
                }
            }
        });

        return $integration->fresh('timeSlots') ?? $integration;
    }

    public function disable(Integration $integration): void
    {
        $integration->update(['disabled' => true]);
    }

    public function enable(Integration $integration): void
    {
        $integration->update(['disabled' => false]);
    }

    public function softDelete(Integration $integration): void
    {
        $integration->delete();
    }

    /**
     * The upsert lookup shared by both the initial attempt and the
     * lost-race recovery path in connect(). Protected (not private) so a
     * test can override it to deterministically force the race window —
     * see IntegrationApiTest's concurrent-connect coverage — without
     * needing genuine multi-process concurrency.
     */
    protected function findUpsertTarget(Organization $org, string $providerIdentifier, string $internalId): ?Integration
    {
        return Integration::withoutGlobalScope('organization')
            ->withTrashed()
            ->where('organization_id', $org->id)
            ->where('provider_identifier', $providerIdentifier)
            ->where('internal_id', $internalId)
            ->first();
    }
}
