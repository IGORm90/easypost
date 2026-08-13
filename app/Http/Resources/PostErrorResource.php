<?php

namespace App\Http\Resources;

use App\Enums\PostState;
use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One failed publication for the `GET /api/posts/errors` UI banner
 * (Task 32). Deliberately narrow, like `CalendarEntryResource`: only
 * what a banner needs to render/link back to the post, plus the
 * group-level `error` summary `MissedPostsService::buildErrorSummary()`
 * writes and the per-segment detail for a caller that wants to show
 * which specific channel(s) failed.
 *
 * Requires `posts.integration` to already be eager-loaded by
 * `PostErrorService` — see that class's docblock.
 *
 * @mixin PostGroup
 */
class PostErrorResource extends JsonResource
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
            'publish_date' => $this->publish_date,
            'error' => $this->error,
            'notified_at' => $this->notified_at,
            'channels' => $posts
                ->filter(fn (Post $post): bool => $post->state === PostState::ERROR)
                ->unique('integration_id')
                ->map(fn (Post $post): array => [
                    'integration' => new IntegrationResource($post->integration),
                    'error' => $post->error,
                ])
                ->values(),
        ];
    }
}
