<?php

namespace App\Http\Resources;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One segment of a chain (see Post's docblock). Used as the leaf of
 * PostGroupResource's `posts[].value[]` array, never returned bare — a
 * segment only makes sense in the context of the channel chain it
 * belongs to.
 *
 * `media_ids` is read back out of `settings['media_ids']`, where
 * PostGroupService stores it: Task 28 (media attachment) hasn't landed
 * yet, so there is no `media` table to join against — see
 * PostGroupService's docblock for why the request's `media_ids` is kept
 * verbatim inside `settings` rather than resolved. UNVERIFIED CLIENT
 * INPUT: these ids are only shape-checked (well-formed ULIDs) on the way
 * in, never checked for existence or organization ownership — a caller
 * consuming this field must not assume any id here refers to a real,
 * owned attachment until Task 28 re-validates it.
 *
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'media_ids' => $this->settings['media_ids'] ?? [],
            'order' => $this->order,
            'parent_post_id' => $this->parent_post_id,
            'state' => $this->state,
            'release_url' => $this->release_url,
            'error' => $this->error,
            'published_at' => $this->published_at,
        ];
    }
}
