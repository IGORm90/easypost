<?php

use App\Http\Middleware\DenyWhileImpersonating;
use App\Http\Middleware\EnforcePlanLimit;
use App\Http\Middleware\EnsureOrganizationRole;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    // Event auto-discovery is on by default (Application::configure()
    // calls ->withEvents() with no arguments before this file's chain
    // ever runs) and scans app/Listeners for any handle() method with a
    // single typed event parameter, registering it automatically IN
    // ADDITION to whatever App\Providers\AppServiceProvider::boot()
    // registers explicitly for the exact same event — found by
    // construction while adding App\Listeners\LogUniqueJobSkipped
    // (Task 30 review round 3): its Log::warning() call fired twice per
    // UniqueJobSkipped event, once per registration. Disabled outright
    // rather than worked around per-listener, since this project's
    // existing convention (see AppServiceProvider::boot(), which already
    // explicitly registers every listener this app has) is explicit
    // Event::listen() wiring, not discovery — this closes the whole
    // class of "silently doubled" bug for every future listener too, not
    // just this one.
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization' => ResolveOrganization::class,
            'role' => EnsureOrganizationRole::class,
            'superadmin' => EnsureSuperadmin::class,
            'deny_while_impersonating' => DenyWhileImpersonating::class,
            'plan' => EnforcePlanLimit::class,
        ]);

        // Lets Sanctum authenticate first-party SPA requests (from
        // SANCTUM_STATEFUL_DOMAINS) via the encrypted session cookie
        // instead of a bearer token, and applies the 'web' group's
        // cookie/session/CSRF middleware ahead of the api routes.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
