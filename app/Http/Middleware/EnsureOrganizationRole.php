<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware alias `role:<ROLE>` (e.g. `role:ADMIN`). Must run after
 * `auth:sanctum` (needs `$request->user()`) and after `organization`
 * (needs `OrganizationContext::get()` to already be set) — both are always
 * true for the routes this task registers, since `role:` is only ever
 * attached alongside those two.
 *
 * "At least" semantics, not "exactly": a SUPERADMIN satisfies `role:ADMIN`
 * because roles are hierarchical (see OrganizationRole::atLeast()) — this
 * middleware never compares role strings/cases directly, so the hierarchy
 * is defined in exactly one place.
 */
class EnsureOrganizationRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $organization = OrganizationContext::get();
        $user = $request->user();
        $required = OrganizationRole::from($role);

        $current = $organization && $user instanceof User
            ? $organization->roleOf($user)
            : null;

        if (! $current || ! $current->atLeast($required)) {
            abort(403, 'You do not have sufficient permissions in this organization.');
        }

        return $next($request);
    }
}
