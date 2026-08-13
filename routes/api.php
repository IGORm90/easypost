<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
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
