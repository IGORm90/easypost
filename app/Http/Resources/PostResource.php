<?php

namespace App\Http\Resources;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One segment of a chain (see Post's docblock). Used as the leaf of
 * PostGroupResource's `posts[].value[]` array, never returned bare — a
 * segment only makes sense in the context of the channel chain it
 * belongs to.
 *
 * Media (Task 28): both `media_ids` and `media` are read from the real
 * `Post::media()` pivot relation, ordered by its `order` column (never
 * insertion/primary-key order — see the `media_post` migration and
 * `Post::media()`'s docblocks for why that distinction matters).
 * `media_ids` keeps the flat id-list shape PUT already accepts, so a GET
 * response still round-trips straight into PUT (PostGroupResource's
 * contract); `media` is the richer per-attachment shape the brief calls
 * for, for rendering. `width`/`height` come straight off `Media`'s own
 * columns (post-review addition: captured once at upload/import time by
 * MediaUploadService::extractDimensions(), never read from disk here) —
 * null for video (and any image `getimagesize()` couldn't parse), same
 * as `thumbnail_url` falling back to null. Both keys rely on
 * `posts.media` being eager-loaded (see
 * PostGroupService's `fresh()`/`loadMissing()` calls and
 * PostController's `load()` calls) — `whenLoaded()` guards against an
 * accidental N+1 if some future caller forgets to.
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
            'media_ids' => $this->whenLoaded('media', fn () => $this->media->pluck('id')->values()->all(), []),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn (Media $media): array => [
                'id' => $media->id,
                'url' => $media->url(),
                'thumbnail_url' => $media->thumbnailUrl(),
                'mime' => $media->mime_type,
                'width' => $media->width,
                'height' => $media->height,
            ])->values()->all(), []),
            'order' => $this->order,
            'parent_post_id' => $this->parent_post_id,
            'state' => $this->state,
            'release_url' => $this->release_url,
            'error' => $this->error,
            'published_at' => $this->published_at,
        ];
    }
}
