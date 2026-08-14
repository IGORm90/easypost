<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    /**
     * `superadmin` route middleware has already confirmed the caller is a
     * superadmin — see EnsureSuperadmin. $user is route-model-bound, so an
     * id for a nonexistent user 404s before this method body runs.
     */
    public function start(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $target = $this->impersonation->start($request, $actor, $user);

        return response()->json(['user' => new UserResource($target)]);
    }

    /**
     * Deliberately not gated by `superadmin` — see ImpersonationService::stop()'s
     * docblock for why.
     */
    public function stop(Request $request): JsonResponse
    {
        $realActor = $this->impersonation->stop($request);

        return response()->json(['user' => new UserResource($realActor)]);
    }
}
