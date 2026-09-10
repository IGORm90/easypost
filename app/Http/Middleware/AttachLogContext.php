<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * LOG_PLAN.md Phase 1.3. Without a request id, every log line is an
 * orphan — nothing joins an error line to the nginx access line or to
 * the request that produced it. Laravel's Context facade is merged into
 * every log record automatically, so this only needs to populate it.
 *
 * Prepended to the api group (see bootstrap/app.php) so the id exists
 * before anything else can fail. ResolveOrganization — a route-level
 * alias, not group middleware, and not always present — adds
 * organization_id/user_id once those are known; see its docblock.
 */
class AttachLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id');

        if (! is_string($requestId) || $requestId === '') {
            $requestId = (string) Str::ulid();
        }

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
