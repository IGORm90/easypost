<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FindSlotRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostDateRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostGroupResource;
use App\Models\PostGroup;
use App\Services\Posts\PostGroupService;
use App\Services\Posts\ShareService;
use App\Services\Posts\SlotFinderService;
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
        private readonly SlotFinderService $slotFinder,
        private readonly ShareService $shares,
    ) {}

    /**
     * GET /api/posts/find-slot — Task 21. Registered ahead of the
     * `{group}` wildcard route (see routes/api.php) so "find-slot" is
     * never swallowed as a group id.
     */
    public function findSlot(FindSlotRequest $request): JsonResponse
    {
        $publishDate = $this->slotFinder->findNextFreeSlot($request->string('integration_id')->toString());

        return response()->json(['publish_date' => $publishDate]);
    }

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

    /**
     * POST /api/posts/{group}/share (Task 22) — mints (or replaces) the
     * group's public share_token and returns the public URL for it.
     * Same 'manage' bar as update/destroy: minting a link that makes
     * this group's content publicly readable is a mutating, content-
     * altering action, not a read.
     */
    public function share(string $group): JsonResponse
    {
        $group = $this->findAuthorized($group, 'manage');
        $url = $this->shares->generate($group);

        return response()->json(['url' => $url]);
    }

    /**
     * DELETE /api/posts/{group}/share (Task 22) — revokes the group's
     * share link. Idempotent: a group with no active token simply gets
     * NULLed again (a no-op), never a 404/409 — "make sure this isn't
     * shared" should always succeed.
     */
    public function unshare(string $group): Response
    {
        $group = $this->findAuthorized($group, 'manage');
        $this->shares->revoke($group);

        return response()->noContent();
    }

    private function findAuthorized(string $id, string $ability): PostGroup
    {
        $group = PostGroup::query()->findOrFail($id);
        Gate::authorize($ability, $group);

        return $group;
    }
}
