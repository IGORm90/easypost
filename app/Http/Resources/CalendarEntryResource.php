<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One calendar entry: a post group with its posts and, per post, the
 * channel (`Integration`) it targets — "плоский список групп с
 * вложенными постами и каналами" per the task-21 brief. Unlike
 * PostGroupResource (Task 20's edit-round-trip shape), this does not
 * group posts back into per-integration chains or echo `settings`/
 * `media_ids`: the calendar only ever renders a group's cards, never
 * feeds a response straight back into PUT /api/posts/{group}. It does
 * carry `share_token`, though: the calendar card shows a "link is
 * active" indicator, and making that one flag cost a per-card fetch of
 * the whole group would be absurd. It is the organization's own data —
 * this endpoint is never reachable without membership.
 *
 * Requires `posts.integration` and `tags` to already be eager-loaded by
 * CalendarService — see that class's docblock for why (avoiding N+1
 * across the whole date range).
 *
 * @mixin PostGroup
 */
class CalendarEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Post> $posts */
        $posts = $this->posts;

        return [
            'id' => $this->id,
            'state' => $this->state,
            'publish_date' => $this->publish_date,
            'share_token' => $this->share_token,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'posts' => $posts->map(fn (Post $post): array => [
                'id' => $post->id,
                'content' => $post->content,
                'state' => $post->state,
                'integration' => new IntegrationResource($post->integration),
            ])->values(),
        ];
    }
}
