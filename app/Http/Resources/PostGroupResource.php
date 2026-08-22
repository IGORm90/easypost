<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The full shape of a post group, `posts` grouped back into the exact
 * `{integration_id, settings, value[]}` shape the request bodies use
 * (see the task-20 brief and ValidatesPostChains) — a GET response can
 * be fed straight back into PUT with `type`/`publish_date` added, which
 * is exactly the round-trip Task 24/25's editor needs. `state` (the
 * real, persisted PostState) stands in for the request's `type`: a
 * group's `type` is only ever a one-time instruction for what state to
 * move it to, not something stored verbatim.
 *
 * @mixin PostGroup
 */
class PostGroupResource extends JsonResource
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
            'error' => $this->error,
            'share_token' => $this->share_token,
            // Block 4: named exactly as the request names them, because
            // this response is documented to be feedable straight back
            // into PUT (see this class's docblock) — the editor does
            // precisely that when it loads a group for editing.
            'repeat_unit' => $this->repeat_unit,
            'repeat_interval' => $this->repeat_interval,
            'repeat_until' => $this->repeat_until,
            'repeat_parent_id' => $this->repeat_parent_id,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('id')->values()),
            'posts' => $posts->groupBy('integration_id')->map(function (Collection $chain, string $integrationId): array {
                $chain = $chain->sortBy('order')->values();

                /** @var Post $root */
                $root = $chain->first();

                return [
                    'integration_id' => $integrationId,
                    'settings' => $this->channelSettings($root),
                    'value' => PostResource::collection($chain)->resolve(),
                ];
            })->values(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The channel-level `settings` sent on `posts[].settings` in the
     * request, stored verbatim on the row's own `settings` column.
     * Per-segment `media_ids` lives in the real `media_post` pivot as of
     * Task 28 (see PostGroupService's docblock), not inside this jsonb
     * column, so there is nothing to strip out here anymore.
     *
     * @return array<string, mixed>
     */
    private function channelSettings(Post $root): array
    {
        return $root->settings ?? [];
    }
}
