<?php

namespace App\Services\Posts;

use App\Models\Comment;
use App\Models\PostGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Owns reads/writes for a post group's internal team comments (Task 22).
 * Authorization (is the caller a member of `$group`'s organization at
 * all) is decided before either method here runs — see
 * CommentController::findAuthorized(), the same
 * PostController::findAuthorized() pattern Task 20 established: a
 * wrong-organization `{group}` id 404s before this class is ever
 * reached, so nothing here re-checks tenancy.
 */
class CommentService
{
    /**
     * @return Collection<int, Comment>
     */
    public function list(PostGroup $group): Collection
    {
        return $group->comments()->with('user')->oldest()->get();
    }

    public function create(PostGroup $group, User $author, string $content): Comment
    {
        return $group->comments()->create([
            'user_id' => $author->id,
            'content' => $content,
        ])->load('user');
    }
}
