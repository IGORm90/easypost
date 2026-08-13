<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal team comment on a post group (Task 22). Only ever reachable
 * by a member of the group's own organization (see CommentController /
 * PostPolicy::view()) — this is deliberately NOT the public-facing
 * shape, so exposing the author's name/email here is fine: a teammate
 * viewing another teammate's comment is the entire point, same bar
 * MemberResource already sits at for the team list.
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
