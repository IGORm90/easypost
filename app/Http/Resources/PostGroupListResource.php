<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One row of GET /api/posts (Block 2): a post group as a list line, not
 * as a card and not as an edit round-trip.
 *
 * Separate from CalendarEntryResource on purpose. That one nests every
 * segment's FULL `content`, which is right for a calendar month (the
 * card shows the first segment and the editor is one click away) and
 * wrong for this endpoint: a page of 25 groups whose threads run five
 * segments each would ship megabytes so the reader can see one trimmed
 * line per row. Here the trimming happens server-side, before the text
 * goes on the wire — which is the entire point of the resource.
 *
 * `channels` is deduplicated by integration id: a thread targeting one
 * channel is several `Post` rows, and without the dedupe the channel's
 * name would repeat once per segment in every list row.
 *
 * Requires `posts.integration` and `tags` eager-loaded and `comments`
 * counted by CalendarService::list() — see that class's docblock for the
 * N+1 reasoning this shares with the calendar.
 *
 * @mixin PostGroup
 */
class PostGroupListResource extends JsonResource
{
    /**
     * Characters of the first segment kept for the row's preview. Long
     * enough to be a useful line at list width, short enough that the
     * page stays a list rather than a feed.
     */
    private const PREVIEW_LENGTH = 200;

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
            // Same one-boolean rule as CalendarEntryResource: the list
            // row needs the indicator, not the recurrence rule.
            'repeats' => $this->repeat_unit !== null,
            // The first segment, the same one CalendarEvent.vue previews
            // on a card — `posts()` is ordered by `order`, so this is the
            // thread root, not an arbitrary row.
            'preview' => Str::limit((string) ($posts->first()->content ?? ''), self::PREVIEW_LENGTH),
            'channels' => $posts
                ->unique('integration_id')
                ->map(fn (Post $post): array => [
                    'id' => $post->integration->id,
                    'provider_identifier' => $post->integration->provider_identifier,
                    'name' => $post->integration->name,
                    'picture' => $post->integration->picture,
                ])
                ->values(),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            // whenCounted, not a bare attribute: this resource is only
            // ever built from a query that counted, but an unconditional
            // field would turn a forgotten withCount() into a per-row
            // query instead of a loud absence.
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
