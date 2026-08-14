<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Media
 *
 * `url`/`thumbnail_url` are resolved through Media::url()/thumbnailUrl()
 * (which go through Storage::disk()->url()), never by exposing
 * `path`/`thumbnail_path` (the raw storage key) directly — the key's
 * shape (`org/{org_id}/{ulid}.{ext}`) is an internal storage detail, not
 * a stable API contract, and Storage::disk()->url() is the only thing
 * that knows how to turn it into something actually fetchable (a fake
 * local path in tests, a real S3/MinIO URL otherwise).
 *
 * `width`/`height` (Task 28 review): read straight off `Media`'s own
 * columns, captured once at upload/import time by
 * MediaUploadService::extractDimensions() — never derived here. `GET
 * /api/media` (this resource) is what Task 29's picker/gallery lists
 * from, so these need to be here, not only on `PostResource`'s
 * already-attached-media shape — a grid needs dimensions to reserve
 * layout space before an image loads. Null for video and any image
 * `getimagesize()` couldn't parse, same as `thumbnail_url`.
 */
class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mime' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'original_filename' => $this->original_filename,
            'url' => $this->url(),
            'thumbnail_url' => $this->thumbnailUrl(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
