<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware alias `deny_while_impersonating`. The single, central
 * enforcement point for the restricted set of account-security actions an
 * impersonated session must never reach — currently
 * `POST /api/profile/api-key/rotate`, and, per this task's brief, the
 * change-password and delete-account endpoints a later task adds. Any
 * route that mutates account security simply gets this alias attached; the
 * check itself never needs to be duplicated per-controller-action.
 *
 * "Impersonating" is defined the same way ImpersonationService defines it:
 * the presence of `impersonator_id` in the session, written by
 * ImpersonationService::start() and cleared by ::stop(). This middleware
 * only reads that key — it never writes it — so it can't itself change
 * who is impersonating whom.
 */
class DenyWhileImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('impersonator_id')) {
            abort(403, 'This action is not available while impersonating another user.');
        }

        return $next($request);
    }
}
