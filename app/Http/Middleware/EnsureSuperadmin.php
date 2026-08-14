<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware alias `superadmin`. Gates POST /api/admin/impersonate/{user}
 * — starting an impersonation session is the one instance-level privilege
 * in this app, so it is checked here rather than through the
 * organization-scoped `role:` middleware (EnsureOrganizationRole), which
 * only ever compares a role *within* the current tenant and has no notion
 * of `is_superadmin` at all.
 *
 * Deliberately NOT applied to POST /api/admin/impersonate/stop: while
 * impersonating, `$request->user()` is the *target* (who may not be a
 * superadmin at all), and stop() must still be reachable in that state —
 * see ImpersonationService::stop(), which authorizes itself off the
 * session-recorded real actor instead.
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_superadmin) {
            abort(403, 'Superadmin access required.');
        }

        return $next($request);
    }
}
