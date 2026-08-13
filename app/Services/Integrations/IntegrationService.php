<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\Organization;
use App\Social\Dto\AuthResultDto;

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
     * between two concurrent connect() calls for the same key.
     */
    public function connect(Organization $org, string $providerIdentifier, AuthResultDto $auth): Integration
    {
        $integration = Integration::withoutGlobalScope('organization')
            ->withTrashed()
            ->where('organization_id', $org->id)
            ->where('provider_identifier', $providerIdentifier)
            ->where('internal_id', $auth->internalId)
            ->first();

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
        $integration->save();

        return $integration;
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
}
