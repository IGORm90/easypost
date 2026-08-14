<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for the current request from the `X-Organization`
 * header, verifies the authenticated user is a member of it, and puts the
 * organization in OrganizationContext for the rest of the request.
 *
 * Task 5 replaces the Task-4 stub (which always returned false, failing
 * every request closed because `organization_user` didn't exist yet) with
 * a real membership lookup. `resolveMembership()` runs a single query
 * through `User::organizations()` (the `organization_user` pivot) scoped to
 * the requested id: it both confirms membership and returns the
 * Organization to store in the context, so there's no separate
 * exists()-then-find() pair. It returns null — and this class then aborts
 * with 403 — for all three "not admitted" cases: no membership row, no
 * authenticated user, and an organization id that doesn't exist at all.
 *
 * The header value is validated as a well-formed ULID *before* it reaches
 * any query: `organization_id`/`id` columns are `char(26)`, so a header
 * that isn't a 26-character ULID (too long, too short, wrong alphabet)
 * would otherwise hit the database and raise a raw QueryException — a
 * denial arriving as a 500 instead of the clean 403 every other rejection
 * path returns, which is a bug in a component whose entire job is to fail
 * closed predictably.
 */
class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization');

        if (! is_string($organizationId) || $organizationId === '' || ! Str::isUlid($organizationId)) {
            abort(403, 'Missing or malformed X-Organization header.');
        }

        $user = $request->user();

        $organization = $user instanceof User
            ? $this->resolveMembership($user, $organizationId)
            : null;

        if (! $organization instanceof Organization) {
            abort(403, 'You are not a member of this organization.');
        }

        OrganizationContext::set($organization);

        return $next($request);
    }

    /**
     * Returns the Organization if $user is a verified member of it, or
     * null otherwise (covers both "no membership row" and "no such
     * organization" — both fail closed identically).
     */
    protected function resolveMembership(User $user, string $organizationId): ?Organization
    {
        return $user->organizations()->whereKey($organizationId)->first();
    }
}
