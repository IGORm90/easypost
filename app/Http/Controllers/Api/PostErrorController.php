<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostErrorsRequest;
use App\Http\Resources\PostErrorResource;
use App\Services\Posts\PostErrorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * HTTP surface for GET /api/posts/errors (Task 32). Pure orchestration —
 * see PostErrorService for the query itself. Registered ahead of the
 * `{group}` wildcard route in routes/api.php so "errors" is never
 * swallowed as a group id, same reasoning as PostController::findSlot()'s
 * docblock.
 */
class PostErrorController extends Controller
{
    public function __construct(
        private readonly PostErrorService $errors,
    ) {}

    public function index(PostErrorsRequest $request): JsonResponse
    {
        $groups = $this->errors->forRange(
            Carbon::parse($request->string('from')->toString()),
            Carbon::parse($request->string('to')->toString()),
        );

        return response()->json(PostErrorResource::collection($groups));
    }
}
