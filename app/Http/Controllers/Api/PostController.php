<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostDateRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostGroupResource;
use App\Models\PostGroup;
use App\Services\Posts\PostGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * HTTP surface for post group CRUD (Task 20). Pure orchestration —
 * request shape/semantic validation lives in
 * StorePostRequest/UpdatePostRequest/UpdatePostDateRequest, every write
 * and business rule (editability, state transitions, cross-tenant
 * `integration_id` authorization) lives in PostGroupService, responses
 * only ever go out through PostGroupResource.
 *
 * `{group}`-scoped actions resolve via `PostGroup::query()->findOrFail()`
 * — never `withoutGlobalScopes()` — so a wrong-organization id 404s
 * exactly like a nonexistent one, same pattern as
 * IntegrationController::findAuthorized(). This is a different rule than
 * the 403 task-20 requires for an `integration_id` referenced *inside* a
 * request body (see PostGroupService::resolveIntegrations()): here the
 * group id IS the resource being addressed directly, there the
 * integration id is merely referenced by one.
 */
class PostController extends Controller
{
    public function __construct(
        private readonly PostGroupService $groups,
    ) {}

    public function store(StorePostRequest $request): JsonResponse
    {
        $group = $this->groups->create($request->validated());

        return response()->json(new PostGroupResource($group), Response::HTTP_CREATED);
    }

    public function show(string $group): JsonResponse
    {
        $group = $this->findAuthorized($group, 'view');

        return response()->json(new PostGroupResource($group->load(['posts', 'tags'])));
    }

    public function update(UpdatePostRequest $request, string $group): JsonResponse
    {
        $group = $this->findAuthorized($group, 'manage');
        $group = $this->groups->update($group, $request->validated());

        return response()->json(new PostGroupResource($group));
    }

    public function destroy(string $group): Response
    {
        $group = $this->findAuthorized($group, 'manage');
        $this->groups->delete($group);

        return response()->noContent();
    }

    public function duplicate(string $group): JsonResponse
    {
        $group = $this->findAuthorized($group, 'manage');
        $duplicate = $this->groups->duplicate($group);

        return response()->json(new PostGroupResource($duplicate), Response::HTTP_CREATED);
    }

    public function updateDate(UpdatePostDateRequest $request, string $group): JsonResponse
    {
        $group = $this->findAuthorized($group, 'manage');
        $group = $this->groups->updateDate($group, $request->date('publish_date'));

        return response()->json(new PostGroupResource($group->load(['posts', 'tags'])));
    }

    private function findAuthorized(string $id, string $ability): PostGroup
    {
        $group = PostGroup::query()->findOrFail($id);
        Gate::authorize($ability, $group);

        return $group;
    }
}
