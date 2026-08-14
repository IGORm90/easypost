<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        }

        DB::transaction(function () use ($organization, $membership, $targetRole) {
            if ($targetRole === OrganizationRole::SUPERADMIN) {
                $this->guardLastSuperadmin($organization);
            }

            $membership->delete();
        });
    }

    public function updateRole(Organization $organization, User $actor, User $target, OrganizationRole $newRole): OrganizationUser
    {
        $membership = $this->findMembership($organization, $target);
        $currentRole = $membership->role;

        $touchesSuperadmin = $currentRole === OrganizationRole::SUPERADMIN || $newRole === OrganizationRole::SUPERADMIN;

        if ($touchesSuperadmin) {
            Gate::forUser($actor)->authorize('manageSuperadmin', $organization);
        }

        DB::transaction(function () use ($organization, $membership, $currentRole, $newRole) {
            if ($currentRole === OrganizationRole::SUPERADMIN && $newRole !== OrganizationRole::SUPERADMIN) {
                $this->guardLastSuperadmin($organization);
            }

            $membership->update(['role' => $newRole]);
        });

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
     * only one SUPERADMIN. Must only ever be called from inside the same
     * DB::transaction() that goes on to perform the removal/demotion (both
     * call sites above do this) — `lockForUpdate()` only serializes
     * concurrent callers for the lifetime of an explicit transaction; used
     * standalone it locks nothing beyond this one query.
     *
     * Without the lock: two concurrent demotions/removals against the
     * last two SUPERADMINs can both run this count while the org still has
     * 2, both see "more than one, safe to proceed", and both commit —
     * leaving zero SUPERADMINs, a state nobody left in the org can recover
     * from (nobody remains who can promote anyone back). With the lock:
     * whichever of the two transactions reaches this query second blocks
     * until the first commits (or rolls back) its own removal/demotion,
     * then re-reads the SUPERADMIN set as it now stands — correctly
     * observing 1, not 2, and aborting before its own mutation runs.
     *
     * Deliberately `lockForUpdate()->get()->count()`, not
     * `lockForUpdate()->count()`: PostgreSQL rejects `SELECT count(*) ...
     * FOR UPDATE` outright ("FOR UPDATE is not allowed with aggregate
     * functions") since a row lock on an aggregated result is meaningless
     * — the lock has to name actual rows, so the rows are fetched and
     * counted in PHP instead.
     */
    private function guardLastSuperadmin(Organization $organization): void
    {
        $superadmins = OrganizationUser::forOrganization($organization)
            ->where('role', OrganizationRole::SUPERADMIN)
            ->lockForUpdate()
            ->get();

        if ($superadmins->count() <= 1) {
            abort(422, 'The last SUPERADMIN of an organization cannot be removed or demoted.');
        }
    }
}
