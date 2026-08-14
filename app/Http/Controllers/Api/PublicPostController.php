<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPostResource;
use App\Services\Posts\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/public/posts/{token} (Task 22) — the one endpoint in this
 * application that is deliberately reachable with no session, no bearer
 * token, and no X-Organization header at all (see routes/api.php: this
 * route carries neither `auth:sanctum` nor `organization`). Every
 * security property this needs (unguessable/revocable token,
 * indistinguishable 404s, throttling, and the single, isolated escape
 * from BelongsToOrganization's tenant scope) lives in
 * ShareService::findForPublic() — this controller is pure orchestration,
 * same as every other controller in this codebase.
 */
class PublicPostController extends Controller
{
    public function __construct(private readonly ShareService $shares) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $group = $this->shares->findForPublic($token, $request->ip() ?? 'unknown');

        return response()->json(new PublicPostResource($group));
    }
}
