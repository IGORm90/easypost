<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Integration;
use App\Models\User;

/**
 * Authorization for managing a connected channel. Not wired to any route
 * yet — Task 13 owns the `integrations` controller and is expected to
 * call `Gate::authorize('manage', $integration)` (or `view`) before
 * touching `IntegrationService`. Defined now, alongside the model and
 * service it guards, so that controller can be added without also having
 * to design the authorization rule under time pressure.
 *
 * Auto-discovered by Laravel's default policy convention (App\Models\X ->
 * App\Policies\XPolicy) — no explicit registration needed.
 */
class IntegrationPolicy
{
    /**
     * Any member of the owning organization may see a connected channel.
     */
    public function view(User $user, Integration $integration): bool
    {
        return $integration->organization->roleOf($user) !== null;
    }

    /**
     * Connecting, disabling, enabling or disconnecting a channel changes
     * what the organization can publish to — same bar as other
     * organization-mutating actions (see OrganizationPolicy): ADMIN or
     * above.
     */
    public function manage(User $user, Integration $integration): bool
    {
        return $integration->organization->roleOf($user)?->atLeast(OrganizationRole::ADMIN) ?? false;
    }
}
