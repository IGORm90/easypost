<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/posts/{group}/comments (Task 22). Shape only — who
 * may post is decided by PostController::findAuthorized() before this
 * request's `authorize()` is even asked (see CommentController), same
 * split UpdatePostRequest documents for the group itself.
 *
 * `content` is stored as-is once it passes these two checks (non-empty,
 * bounded length); escaping it for display is the renderer's job, not
 * this layer's — see the task-22 brief.
 */
class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000'],
        ];
    }
}
