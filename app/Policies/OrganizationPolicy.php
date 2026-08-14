<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/**
 * Instance-level authorization rules that a route-level `role:ADMIN`
 * middleware cannot express, because they depend on the *payload* of the
 * request (which role is being granted, or which member is being touched),
 * not just on the fixed minimum role a route requires.
 *
 * Auto-discovered by Laravel's default policy convention (App\Models\X ->
 * App\Policies\XPolicy) — no explicit registration needed.
 */
class OrganizationPolicy
{
    /**
     * Only a SUPERADMIN may mint an invite that grants the SUPERADMIN
     * role. Without this, any ADMIN — who can already create invites for
     * ADMIN/USER — could mint themselves (or an accomplice) a SUPERADMIN
     * invite and accept it, a straight privilege escalation the `role:ADMIN`
     * route gate alone cannot prevent since it only sees the route, never
     * the `role` field in the request body.
     */
    public function inviteAs(User $user, Organization $organization, OrganizationRole $role): bool
    {
        if ($role === OrganizationRole::SUPERADMIN) {
            return $organization->roleOf($user) === OrganizationRole::SUPERADMIN;
        }

        return $organization->roleOf($user)?->atLeast(OrganizationRole::ADMIN) ?? false;
    }

    /**
     * Only a SUPERADMIN may grant or revoke the SUPERADMIN role on another
     * member, or remove a SUPERADMIN member outright. Without this, any
     * ADMIN — who can already remove/demote ADMIN and USER members — could
     * demote every other SUPERADMIN down to ADMIN (or delete them) and
     * become the organization's sole power, which is the same class of
     * escalation `inviteAs()` above guards against on the invite path.
     *
     * This is deliberately broader than just "the last SUPERADMIN":
     * MembershipService separately enforces that the last SUPERADMIN can
     * never be removed/demoted by anyone, including another SUPERADMIN.
     */
    public function manageSuperadmin(User $user, Organization $organization): bool
    {
        return $organization->roleOf($user) === OrganizationRole::SUPERADMIN;
    }
}
