<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * GET|POST /api/posts/{group}/comments (Task 22). `{group}` is resolved
 * and authorized the exact same way PostController does it — via
 * PostGroup::query()->findOrFail() (never withoutGlobalScopes()) plus
 * Gate::authorize('view', ...) — so a group id from another organization
 * 404s exactly like one that doesn't exist, same bar PostPolicy::view()
 * already sets for viewing the group itself: plain membership, nothing
 * more, since reading/writing team comments is no more sensitive than
 * reading/writing the post content they're attached to.
 */
class CommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    public function index(string $group): JsonResponse
    {
        $group = $this->findAuthorized($group);

        return response()->json(CommentResource::collection($this->comments->list($group)));
    }

    public function store(StoreCommentRequest $request, string $group): JsonResponse
    {
        $group = $this->findAuthorized($group);

        /** @var User $author */
        $author = auth()->user();
        $comment = $this->comments->create($group, $author, $request->string('content')->toString());

        return response()->json(new CommentResource($comment), Response::HTTP_CREATED);
    }

    private function findAuthorized(string $id): PostGroup
    {
        $group = PostGroup::query()->findOrFail($id);
        Gate::authorize('view', $group);

        return $group;
    }
}
