<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSetRequest;
use App\Http\Requests\UpdateSetRequest;
use App\Http\Resources\SetResource;
use App\Models\Set;
use App\Services\Posts\SetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * HTTP surface for organization-scoped channel-set CRUD (Task 23). Pure
 * orchestration — ownership validation lives in
 * StoreSetRequest/UpdateSetRequest, writes in SetService, responses only
 * through SetResource.
 *
 * `{set}`-scoped actions resolve via `Set::query()->findOrFail()` — never
 * `withoutGlobalScopes()` — so a wrong-organization id 404s exactly like
 * a nonexistent one, the same pattern TagController documents for
 * `{tag}`. No dedicated policy: the `organization` route middleware
 * already proves membership before this controller runs, and sets carry
 * no ownership concept finer than "belongs to this organization".
 */
class SetController extends Controller
{
    public function __construct(
        private readonly SetService $sets,
    ) {}

    public function index(): JsonResponse
    {
        $sets = Set::query()->orderBy('name')->get();

        return response()->json(SetResource::collection($sets));
    }

    public function store(StoreSetRequest $request): JsonResponse
    {
        $set = $this->sets->create($request->validated());

        return response()->json(new SetResource($set), Response::HTTP_CREATED);
    }

    public function update(UpdateSetRequest $request, string $set): JsonResponse
    {
        $set = Set::query()->findOrFail($set);
        $set = $this->sets->update($set, $request->validated());

        return response()->json(new SetResource($set));
    }

    public function destroy(string $set): Response
    {
        $set = Set::query()->findOrFail($set);
        $this->sets->delete($set);

        return response()->noContent();
    }
}
