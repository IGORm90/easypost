<?php

use App\Http\Controllers\Api\SocialAuthController;
use Illuminate\Support\Facades\Route;

// Deliberately registered here, not in routes/api.php, even though the
// URL still lives under /api for consistency with the rest of the API.
//
// routes/api.php's routes only get a session when Sanctum's
// EnsureFrontendRequestsAreStateful::fromFrontend() recognises the
// request as same-origin (Referer/Origin matching
// SANCTUM_STATEFUL_DOMAINS) — see bootstrap/app.php's statefulApi() and
// vendor/laravel/sanctum/src/Http/Middleware/EnsureFrontendRequestsAreStateful.php.
// That works for GET /api/auth/oauth/{driver}/redirect, which the SPA
// calls itself via same-origin fetch. It does NOT work for
// GET /api/auth/oauth/{driver}/callback: that request is a top-level
// browser navigation issued by the *provider* (github.com,
// accounts.google.com redirecting back), so its Referer/Origin is the
// provider's, never ours. fromFrontend() returns false, the inner
// StartSession/EncryptCookies pipeline never runs, and
// $request->session() throws — which would either 500 every real login or,
// if that were papered over, silently disable Socialite's session-backed
// `state` CSRF check (see SocialAuthController's docblock) exactly when a
// real cross-site callback is the case that check exists for.
//
// Registering both actions under the plain "web" middleware group (below)
// instead gives them EncryptCookies + StartSession unconditionally,
// independent of Referer/Origin. The session cookie itself is still sent
// by the browser on this cross-site top-level GET navigation (it's
// SameSite=Lax — see config/session.php's default, which already matches
// what Sanctum forces — and Lax cookies ride along on top-level "safe"
// navigations like this one), so Socialite's normal stateful redirect()/
// user() state check works correctly once the middleware that reads it is
// actually present. Exceptions still render as JSON like the rest of the
// API: bootstrap/app.php's shouldRenderJsonWhen() matches on the request
// path ("api/*"), not on which route file registered it.
Route::prefix('api/auth/oauth')->group(function () {
    Route::get('{driver}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('{driver}/callback', [SocialAuthController::class, 'callback']);
});

// Serves the Vue SPA shell for every route except the API, Sanctum and
// Horizon prefixes, which are handled by their own route files/packages.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|horizon).*$');
