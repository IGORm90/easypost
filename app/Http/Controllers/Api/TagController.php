<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\Posts\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * HTTP surface for organization-scoped tag CRUD (Task 21). Pure
 * orchestration — uniqueness/colour-format validation lives in
 * StoreTagRequest/UpdateTagRequest, writes in TagService, responses only
 * through TagResource.
 *
 * `{tag}`-scoped actions resolve via `Tag::query()->findOrFail()` — never
 * `withoutGlobalScopes()` — so a wrong-organization id 404s exactly like
 * a nonexistent one, the same pattern PostController documents for
 * `{group}`. No dedicated policy: the `organization` route middleware
 * already proves membership before this controller runs, and tags carry
 * no ownership concept finer than "belongs to this organization".
 */
class TagController extends Controller
{
    public function __construct(
        private readonly TagService $tags,
    ) {}

    public function index(): JsonResponse
    {
        $tags = Tag::query()->orderBy('name')->get();

        return response()->json(TagResource::collection($tags));
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = $this->tags->create($request->validated());

        return response()->json(new TagResource($tag), Response::HTTP_CREATED);
    }

    public function update(UpdateTagRequest $request, string $tag): JsonResponse
    {
        $tag = Tag::query()->findOrFail($tag);
        $tag = $this->tags->update($tag, $request->validated());

        return response()->json(new TagResource($tag));
    }

    public function destroy(string $tag): Response
    {
        $tag = Tag::query()->findOrFail($tag);
        $this->tags->delete($tag);

        return response()->noContent();
    }
}
