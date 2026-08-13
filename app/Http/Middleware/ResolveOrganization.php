<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for the current request from the `X-Organization`
 * header, verifies the authenticated user is a member of it, and puts the
 * organization id in OrganizationContext for the rest of the request.
 *
 * STUBBED pending Task 5: `userBelongsToOrganization()` cannot query the
 * `organization_user` pivot table yet because it doesn't exist until
 * Task 5's migrations land. It currently always returns false, so this
 * middleware fails closed — every request is rejected with 403 rather than
 * silently authorising everyone. Task 5 (or the task that first routes
 * traffic through this middleware) must replace the body of
 * userBelongsToOrganization() with a real lookup, e.g.:
 *
 *     return $user->organizations()->whereKey($organizationId)->exists();
 *
 * No other part of this class needs to change for that swap.
 */
class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization');

        if (! is_string($organizationId) || $organizationId === '') {
            abort(403, 'Missing X-Organization header.');
        }

        $user = $request->user();

        if (! $user instanceof Authenticatable || ! $this->userBelongsToOrganization($user, $organizationId)) {
            abort(403, 'You are not a member of this organization.');
        }

        OrganizationContext::set($organizationId);

        return $next($request);
    }

    /**
     * STUB — see class docblock. Always false until Task 5 provides the
     * organization_user pivot to check membership against.
     */
    protected function userBelongsToOrganization(Authenticatable $user, string $organizationId): bool
    {
        return false;
    }
}
