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

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations),
        ]);
    }
}
