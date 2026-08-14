<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The signed-in user has proven they own their email address."
 *
 * Registered as the `verified` alias in bootstrap/app.php in place of
 * Laravel's own middleware of this name, for one reason: the framework's
 * version answers a request that does not announce JSON by redirecting to
 * a route named `verification.notice`, and this application defines no
 * such route — it is an API with a separate SPA, and the notice a browser
 * would land on is a client-side page, not a Laravel view.
 *
 * The consequence was not theoretical. A POST to
 * `/api/invites/{token}/accept` with no `Accept: application/json` header
 * — a curl one-liner, a link opened by a mail client, anything not the
 * SPA — raised RouteNotFoundException, i.e. a 500 and a stack trace where
 * a 403 and a sentence belonged. Refusing a request is not an error
 * condition and must not be reported as one.
 *
 * So: the same rule, one answer, regardless of what the caller asked for.
 *
 * Deliberately no redirect branch at all rather than a redirect to some
 * SPA URL — every route this guards lives under /api, and a redirect is
 * not a useful answer to an API call. If a server-rendered page ever
 * needs this middleware, give it its own, and let this one keep meaning
 * exactly what it says.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof MustVerifyEmail || ! $user->hasVerifiedEmail()) {
            abort(403, 'Your email address is not verified.');
        }

        return $next($request);
    }
}
