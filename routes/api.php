<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

// GET /api/auth/oauth/{driver}/{redirect,callback} are deliberately *not*
// declared here — see routes/web.php for why: they need a session
// regardless of the caller's Referer/Origin, which this file's
// statefulApi()-gated 'api' group cannot guarantee.
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::post('password/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('password/reset', [PasswordResetController::class, 'reset']);

    // `signed`: the URL itself (not a session/token) is the credential —
    // see EmailVerificationService::verify() and VerifyEmailNotification.
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('email/resend', [EmailVerificationController::class, 'resend']);
    });
});

/*
 * Organizations, members and invites (Task 9). `auth:sanctum` gates all of
 * it. Routes that act on "the current organization" additionally require
 * the `organization` middleware (validates X-Organization, checks real
 * membership, sets OrganizationContext — see ResolveOrganization); the
 * mutating ones among those further require `role:ADMIN` (ADMIN or,
 * because roles are hierarchical, SUPERADMIN — see
 * OrganizationRole::atLeast()). Instance-level rules that a fixed
 * per-route role requirement can't express (SUPERADMIN-only invite
 * escalation, last-SUPERADMIN protection) live in
 * InviteService/MembershipService/OrganizationPolicy, not in route
 * middleware.
 *
 * `invites/{token}/accept` deliberately sits outside the `organization`
 * group: accepting an invite is how a user gains membership in the first
 * place, so it must work with no X-Organization header and no prior
 * membership at all.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::post('organizations', [OrganizationController::class, 'store']);
    Route::post('invites/{token}/accept', [InviteController::class, 'accept']);

    Route::middleware('organization')->prefix('organizations/current')->group(function () {
        Route::put('', [OrganizationController::class, 'update'])->middleware('role:ADMIN');
        Route::get('members', [MemberController::class, 'index']);
        Route::delete('members/{user}', [MemberController::class, 'destroy'])->middleware('role:ADMIN');
        Route::put('members/{user}/role', [MemberController::class, 'updateRole'])->middleware('role:ADMIN');
        Route::post('invites', [InviteController::class, 'store'])->middleware('role:ADMIN');
    });
});

/*
 * Profile and impersonation (Task 10). `auth:sanctum` gates all of it;
 * neither is organization-scoped, so `organization`/`role:` never apply
 * here.
 *
 * `deny_while_impersonating` on the api-key rotation route is the one
 * currently-built member of the restricted set an impersonated session
 * must never reach (alongside password-change and account-deletion, which
 * land in later tasks and pick up the same alias then) — see
 * DenyWhileImpersonating's docblock.
 *
 * `superadmin` gates *starting* impersonation only; stopping it
 * deliberately has no role middleware at all — see
 * ImpersonationController::stop()'s docblock for why the impersonated
 * session itself must be able to reach it.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/api-key/rotate', [ProfileController::class, 'rotateApiKey'])
        ->middleware('deny_while_impersonating');

    // `stop` (a literal path segment) is registered before `{user}` (a
    // wildcard) deliberately: Laravel matches routes in registration
    // order, so if the wildcard came first, POST /admin/impersonate/stop
    // would itself match `{user}` with $user = 'stop' and 404 on the
    // route-model binding lookup, never reaching stop() at all.
    Route::post('admin/impersonate/stop', [ImpersonationController::class, 'stop']);
    Route::post('admin/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->middleware('superadmin');
});

/*
 * Channels (Task 13). `auth:sanctum` gates all of it.
 *
 * `providers` is a catalogue endpoint (no tenant), so it sits outside the
 * `organization` group entirely.
 *
 * `{provider}/callback` deliberately does NOT require the `organization`
 * middleware/X-Organization header — see IntegrationController::callback()
 * and OAuthStateService's docblocks for why: the organization for the
 * connect is the one bound to the OAuth `state`, resolved by
 * OAuthStateService, never anything this request could otherwise supply.
 * Every other route here (list, auth-url, and the `{id}`-scoped actions)
 * is a normal tenant-scoped action and requires it, same as
 * organizations/current/* above. `auth-url` additionally requires
 * `role:ADMIN`: starting a connect flow carries the same authorization
 * bar as completing one (see IntegrationPolicy::manage()).
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('providers', [IntegrationController::class, 'providers']);

    Route::prefix('integrations')->group(function () {
        Route::post('{provider}/callback', [IntegrationController::class, 'callback']);

        Route::middleware('organization')->group(function () {
            Route::get('/', [IntegrationController::class, 'index']);
            Route::post('{provider}/auth-url', [IntegrationController::class, 'authUrl'])
                ->middleware('role:ADMIN');
            Route::get('{id}/options/{option}', [IntegrationController::class, 'options']);
            Route::put('{id}', [IntegrationController::class, 'update']);
            Route::post('{id}/disable', [IntegrationController::class, 'disable']);
            Route::post('{id}/enable', [IntegrationController::class, 'enable']);
            Route::delete('{id}', [IntegrationController::class, 'destroy']);
        });
    });
});

/*
 * Post group CRUD (Task 20). `auth:sanctum` + `organization` gate all of
 * it — plain membership is enough (no `role:` requirement): scheduling
 * and editing posts is everyday content work for any member, same bar as
 * PostPolicy documents. `{group}` is resolved and authorized inside
 * PostController, not via route-model binding, so a wrong-organization
 * id 404s exactly like a nonexistent one (see PostController's
 * docblock).
 */
Route::middleware(['auth:sanctum', 'organization'])->prefix('posts')->group(function () {
    Route::post('/', [PostController::class, 'store']);
    Route::get('{group}', [PostController::class, 'show']);
    Route::put('{group}', [PostController::class, 'update']);
    Route::delete('{group}', [PostController::class, 'destroy']);
    Route::post('{group}/duplicate', [PostController::class, 'duplicate']);
    Route::patch('{group}/date', [PostController::class, 'updateDate']);
});
