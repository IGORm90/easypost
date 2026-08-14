<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostErrorController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicPostController;
use App\Http\Controllers\Api\SetController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\TagController;
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
    // `verified`: accepting an invite is the one unauthenticated-to-member
    // transition in the app, and the address match in InviteService is the
    // only thing standing between a forwarded token and membership at the
    // invited role. That check is worth nothing if the address was never
    // proven — so this is where the click is required. Final review,
    // batch 4 (tenancy).
    Route::post('invites/{token}/accept', [InviteController::class, 'accept'])->middleware('verified');

    Route::middleware('organization')->prefix('organizations/current')->group(function () {
        Route::put('', [OrganizationController::class, 'update'])->middleware('role:ADMIN');
        Route::get('members', [MemberController::class, 'index']);
        // Plain membership: how much of your own organization's quota is
        // spent is not privileged relative to the people it constrains,
        // and is already derivable by anyone who can list channels, posts
        // and media. See PlanController.
        Route::get('plan', [PlanController::class, 'show']);
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
 *
 * `plan:channels` (Task 36) sits on `auth-url`, not on `callback`: the
 * callback route has no `organization` middleware/context for the plan
 * middleware to read (see EnforcePlanLimit's docblock) — auth-url is
 * still reached by every connect flow, OAuth and manual-token alike, so
 * gating it here covers "starting a connect" regardless of provider. The
 * actual guarantee against a race remains IntegrationService::connect()'s
 * own withLimit() call, not this middleware.
 *
 * `throttle:10,1` on `auth-url` (final-review batch 2, Important 5) — the
 * same budget `media/from-url` carries, and for the same reason: this is
 * the second route in this application that makes a SERVER-ORIGINATED
 * request to a host the caller names. `MastodonProvider::registerOrReuseApp()`
 * issues a fresh `POST https://{instance}/api/v1/apps` for every host
 * that misses the per-host cache, so without a limit an authenticated
 * admin can drive unbounded outbound POSTs at arbitrary hosts — a
 * reflection/amplification primitive, and a way to tie up workers.
 * `role:ADMIN` bounds WHO, not HOW MANY.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('providers', [IntegrationController::class, 'providers']);

    Route::prefix('integrations')->group(function () {
        Route::post('{provider}/callback', [IntegrationController::class, 'callback']);

        Route::middleware('organization')->group(function () {
            Route::get('/', [IntegrationController::class, 'index']);
            Route::post('{provider}/auth-url', [IntegrationController::class, 'authUrl'])
                ->middleware(['role:ADMIN', 'plan:channels', 'throttle:10,1']);
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
 *
 * `plan:posts` (Task 36) gates `store` and `{group}/duplicate` — the two
 * actions that add a new `PostGroup` row (and therefore new monthly
 * usage); duplicate() was missing this pre-check in the original Task 36
 * pass (round 1 review, Critical 1) even though PostGroupService::
 * duplicate() inserts a `PostGroup` exactly like create() does. The real
 * guarantee against a race is PostGroupService::create()'s/duplicate()'s
 * own withLimit() call, not this middleware.
 */
Route::middleware(['auth:sanctum', 'organization'])->prefix('posts')->group(function () {
    Route::post('/', [PostController::class, 'store'])->middleware('plan:posts');
    // 'find-slot' (a literal path segment) is registered before
    // '{group}' (a wildcard) deliberately — same reason
    // admin/impersonate/stop precedes admin/impersonate/{user} above:
    // Laravel matches route registration order, so the wildcard would
    // otherwise swallow GET /api/posts/find-slot as $group = 'find-slot'
    // and 404 on the findOrFail() lookup, never reaching findSlot() at
    // all.
    Route::get('find-slot', [PostController::class, 'findSlot']);
    // Task 32: GET /api/posts/errors — failed publications for a UI
    // banner. Registered ahead of '{group}' for the identical
    // route-order reason as 'find-slot' above. Same tenant bar as every
    // other route in this group (auth:sanctum + organization);
    // PostErrorService's query is scoped by BelongsToOrganization's
    // normal global scope, not a manual withoutGlobalScope() the way
    // MissedPostsService's scan queries are.
    Route::get('errors', [PostErrorController::class, 'index']);
    Route::get('{group}', [PostController::class, 'show']);
    Route::put('{group}', [PostController::class, 'update']);
    Route::delete('{group}', [PostController::class, 'destroy']);
    Route::post('{group}/duplicate', [PostController::class, 'duplicate'])->middleware('plan:posts');
    Route::patch('{group}/date', [PostController::class, 'updateDate']);
    Route::post('{group}/retry', [PostController::class, 'retry']);

    // Task 22. `{group}` is resolved/authorized inside each controller
    // the same PostGroup::query()->findOrFail() + Gate::authorize() way
    // as every other {group} action above — see CommentController's and
    // PostController::findAuthorized()'s docblocks.
    Route::get('{group}/comments', [CommentController::class, 'index']);
    Route::post('{group}/comments', [CommentController::class, 'store']);
    Route::post('{group}/share', [PostController::class, 'share']);
    Route::delete('{group}/share', [PostController::class, 'unshare']);
});

/*
 * Task 22: GET /api/public/posts/{token}. Deliberately outside every
 * auth/organization/role middleware group in this file — this is the one
 * endpoint in the whole application meant to work for a caller with no
 * session and no membership at all. See PublicPostController and
 * ShareService::findForPublic() for how it stays safe without any of
 * that: an unguessable/revocable token standing in for the tenant check,
 * IP-keyed throttling, and a response shape that carries no ids, no
 * organization data and no comments.
 */
Route::prefix('public')->group(function () {
    Route::get('posts/{token}', [PublicPostController::class, 'show']);
});

/*
 * Calendar and tags (Task 21). Same auth/organization bar as post group
 * CRUD above — plain membership, no `role:` requirement: browsing the
 * calendar and managing tags is everyday content work, not an
 * administrative action.
 */
Route::middleware(['auth:sanctum', 'organization'])->group(function () {
    Route::get('calendar', [CalendarController::class, 'index']);

    Route::prefix('tags')->group(function () {
        Route::get('/', [TagController::class, 'index']);
        Route::post('/', [TagController::class, 'store']);
        Route::put('{tag}', [TagController::class, 'update']);
        Route::delete('{tag}', [TagController::class, 'destroy']);
    });
});

/*
 * Sets and signatures (Task 23). Same auth/organization bar as tags
 * above — plain membership, no `role:` requirement: saving a channel
 * preset or a reusable signature is everyday content work, not an
 * administrative action. `{set}`/`{signature}`-scoped actions are
 * resolved and authorized inside their controllers the same
 * `Model::query()->findOrFail()` way as `{tag}` above, not via route-model
 * binding — see SetController's/SignatureController's docblocks.
 */
Route::middleware(['auth:sanctum', 'organization'])->group(function () {
    Route::prefix('sets')->group(function () {
        Route::get('/', [SetController::class, 'index']);
        Route::post('/', [SetController::class, 'store']);
        Route::put('{set}', [SetController::class, 'update']);
        Route::delete('{set}', [SetController::class, 'destroy']);
    });

    Route::prefix('signatures')->group(function () {
        Route::get('/', [SignatureController::class, 'index']);
        Route::post('/', [SignatureController::class, 'store']);
        Route::put('{signature}', [SignatureController::class, 'update']);
        Route::delete('{signature}', [SignatureController::class, 'destroy']);
    });
});

/*
 * Media library (Task 27). Same auth/organization bar as sets/signatures
 * above — plain membership, no `role:` requirement: uploading/browsing
 * library assets is everyday content work. `{id}`-scoped destroy is
 * resolved and authorized inside MediaController the same
 * `Media::query()->findOrFail()` + `Gate::authorize()` way as
 * IntegrationController's `{id}` actions, not via route-model binding.
 *
 * `from-url` additionally carries a `throttle` limiter of its own,
 * tighter than the implicit per-minute default the rest of the API
 * group relies on: unlike every other route in this file, it makes a
 * server-side HTTP request on the caller's behalf (SSRF-guarded via
 * App\Support\SafeRemoteHost, capped at 30s/100MB by
 * MediaUploadService::download()) -- the throttle bounds how often a
 * single caller can make this app hold a worker for that long, on top
 * of (not instead of) the per-request cap. See MediaUploadService::
 * download()'s docblock for the full reasoning.
 *
 * `plan:media_storage` (Task 36) gates only `store` (the direct-upload
 * route) — its file size is known upfront, off the raw request, unlike
 * `from-url`'s (unknown until the download completes; see
 * EnforcePlanLimit's docblock for why that route relies entirely on
 * MediaUploadService::persist()'s own check without a middleware
 * pre-check ahead of it).
 */
Route::middleware(['auth:sanctum', 'organization'])->prefix('media')->group(function () {
    Route::get('/', [MediaController::class, 'index']);
    Route::post('/', [MediaController::class, 'store'])->middleware('plan:media_storage');
    Route::post('from-url', [MediaController::class, 'importFromUrl'])->middleware('throttle:10,1');
    Route::delete('{id}', [MediaController::class, 'destroy']);
});
