<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Owns member removal and role changes for the current organization,
 * including the two invariants no controller/policy alone can express:
 *
 * - The last SUPERADMIN of an organization can never be removed or
 *   demoted (by anyone, including another SUPERADMIN) — an organization
 *   must always have at least one SUPERADMIN able to manage it.
 * - Touching a SUPERADMIN membership (removing one, or promoting/demoting
 *   to/from SUPERADMIN) requires the actor to themselves be a SUPERADMIN —
 *   enforced via OrganizationPolicy::manageSuperadmin(), so an ADMIN
 *   (already allowed past the route's `role:ADMIN` gate) cannot use member
 *   management to strip out every SUPERADMIN one at a time.
 */
class MembershipService
{
    /**
     * Every direct query against OrganizationUser here goes through
     * ::forOrganization() (see that model's docblock) — never a bare
     * where('organization_id', ...) that could be forgotten on a future
     * edit and silently touch another tenant's membership.
     */
    public function remove(Organization $organization, User $actor, User $target): void
    {
        $membership = $this->findMembership($organization, $target);
        $targetRole = $membership->role;

        if ($targetRole === OrganizationRole::SUPERADMIN) {
            Gate::forUser($actor)->authorize('manageSuperadmin', $organization);
            $this->guardLastSuperadmin($organization);
        }

        $membership->delete();
    }

    public function updateRole(Organization $organization, User $actor, User $target, OrganizationRole $newRole): OrganizationUser
    {
        $membership = $this->findMembership($organization, $target);
        $currentRole = $membership->role;

        $touchesSuperadmin = $currentRole === OrganizationRole::SUPERADMIN || $newRole === OrganizationRole::SUPERADMIN;

        if ($touchesSuperadmin) {
            Gate::forUser($actor)->authorize('manageSuperadmin', $organization);
        }

        if ($currentRole === OrganizationRole::SUPERADMIN && $newRole !== OrganizationRole::SUPERADMIN) {
            $this->guardLastSuperadmin($organization);
        }

        $membership->update(['role' => $newRole]);

        return $membership;
    }

    /**
     * 404s (not 403/422) when $target has no membership row in
     * $organization: this isn't a cross-tenant leak — the caller is
     * already a verified ADMIN+ of $organization (route-gated), and "is
     * this user on your team" is exactly what the member list they already
     * have access to reveals. It is a genuine "no such resource".
     */
    private function findMembership(Organization $organization, User $target): OrganizationUser
    {
        $membership = OrganizationUser::forOrganization($organization)
            ->where('user_id', $target->id)
            ->first();

        if (! $membership instanceof OrganizationUser) {
            abort(404, 'This user is not a member of this organization.');
        }

        return $membership;
    }

    /**
     * Blocks the action outright — 422 — when $organization currently has
     * only one SUPERADMIN. Called before the removal/demotion is applied,
     * so the count still reflects the pre-change state.
     */
    private function guardLastSuperadmin(Organization $organization): void
    {
        $superadminCount = OrganizationUser::forOrganization($organization)
            ->where('role', OrganizationRole::SUPERADMIN)
            ->count();

        if ($superadminCount <= 1) {
            abort(422, 'The last SUPERADMIN of an organization cannot be removed or demoted.');
        }
    }
}
