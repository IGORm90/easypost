<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organizations,
        private readonly AuthService $auth,
    ) {}

    /**
     * Creates the user and their first organization ("{name}'s workspace",
     * with the user as SUPERADMIN) atomically: either both exist or
     * neither does.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        [$user, $organization] = DB::transaction(function () use ($request) {
            $user = User::create($request->validated());
            $organization = $this->organizations->createForUser($user, "{$user->name}'s workspace");

            return [$user, $organization];
        });

        // Dispatched after the transaction commits, not inside it: a queued
        // notification (QUEUE_CONNECTION=redis in production) can be picked
        // up by a worker before an in-transaction commit lands, which would
        // fail to find the just-created user.
        $user->sendEmailVerificationNotification();

        return response()->json([
            'user' => new UserResource($user),
            'organization' => new OrganizationResource($organization),
        ], Response::HTTP_CREATED);
    }

    /**
     * Public, so the guest-only login/register screens know whether to offer
     * "Create an account" before the visitor fills in a form that would be
     * refused.
     */
    public function registration(): JsonResponse
    {
        return response()->json(['enabled' => (bool) config('auth.registration_enabled')]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attemptLogin(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip() ?? '',
        );

        $this->auth->login($request, $user);

        // Same payload shape as me() below, deliberately: the SPA's router
        // guard fetches /auth/me at most once per app load, and login()
        // marking the store ready means the first post-login navigation
        // never makes that call. If login() described the session less
        // completely than me() does, the app would behave differently
        // depending on whether the user had just signed in or reloaded the
        // tab — which is exactly the 403-on-every-request bug this fixes.
        return response()->json([
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations),
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);

        return response()->noContent();
    }

    /**
     * The SPA's session bootstrap. Beyond the user and their memberships
     * it answers two questions nothing else in the API could (Task 1.7):
     * may this person open the admin screen, and is this session
     * currently borrowed from someone else.
     *
     * Both sit on the response rather than inside UserResource on
     * purpose. That resource's docblock calls its array the entire
     * contract of what it can return, and RegistrationTest asserts
     * `user.is_superadmin` is absent from it — an instance-level
     * privilege has no business riding along with every login response
     * and every member listing. Here it is scoped to the one endpoint
     * that describes the current session.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations),
            // Cast, not passed through: `users.is_superadmin` is
            // NOT NULL with a database default, so a User instance that
            // has not been round-tripped through a read carries null for
            // it rather than false. The SPA gates a route on this value
            // and must never have to tell those two apart.
            'is_superadmin' => (bool) $user->is_superadmin,
            'impersonator' => $this->impersonator($request),
        ]);
    }

    /**
     * The real actor behind an impersonated session, or null for an
     * ordinary one. `impersonator_id` is the session key
     * ImpersonationService writes and DenyWhileImpersonating reads —
     * this is the only place its value is turned back into a person.
     *
     * A missing user is treated as no impersonation rather than an
     * error: the account can be deleted mid-session, and
     * ImpersonationService::stop() already handles that case properly
     * when the user tries to leave. Failing this endpoint would take the
     * whole SPA down instead.
     *
     * @return array{id: string, name: string}|null
     */
    private function impersonator(Request $request): ?array
    {
        $id = $request->session()->get('impersonator_id');

        if ($id === null) {
            return null;
        }

        $actor = User::query()->find($id);

        return $actor === null ? null : ['id' => $actor->id, 'name' => $actor->name];
    }
}
