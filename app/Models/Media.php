<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Social\Dto\MediaDto;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * One organization-scoped file in the media library (Task 27): an
 * uploaded or URL-imported image/video, plus its generated thumbnail
 * (images only). `path`/`thumbnail_path` are keys on the `media` disk
 * (S3/MinIO) — never a local filesystem path — resolved through
 * `url()`/`thumbnailUrl()` below rather than read directly, since the
 * disk driver (fake in tests, S3 in every real environment) is the only
 * thing that knows how to turn a key into a fetchable URL.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $disk
 * @property string $path
 * @property string|null $thumbnail_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $original_filename
 * @property string|null $created_by
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    protected $table = 'media';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'disk',
        'path',
        'thumbnail_path',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'original_filename',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every `Post` segment (any organization member's, any channel —
     * see the `media_post` migration's docblock) this file is attached
     * to. Task 28 owns writing to this relation; Task 27 only needs to
     * read it, for MediaController::destroy()'s 409 check.
     *
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'media_post');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function thumbnailUrl(): ?string
    {
        if ($this->thumbnail_path === null) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->thumbnail_path);
    }

    /**
     * Task 28's expected shape for handing this file to the posting
     * pipeline: a MediaDto with $sizeBytes always populated from this
     * row's own `size_bytes` column (captured once, at upload time, by
     * MediaUploadService — see that class's and MediaDto's docblocks
     * for why it must never be re-derived from the disk). $path carries
     * the storage key, matching MediaDto::$path's "storage-disk-resolved
     * path" contract. $remoteId is always null here — a fresh MediaDto
     * for an attachment that hasn't been pre-uploaded to any network
     * yet; only a provider's SupportsMedia::uploadMedia() ever sets it.
     */
    public function toDto(?string $altText = null): MediaDto
    {
        return new MediaDto(
            path: $this->path,
            mimeType: $this->mime_type,
            sizeBytes: $this->size_bytes,
            altText: $altText,
            originalFilename: $this->original_filename,
            // Block 5.0.4: the URL-based networks (Instagram, Threads)
            // publish by giving the Graph API a link it fetches itself.
            // Built here, from the row's own disk, rather than inside a
            // provider: `app/Social` must not know what a disk is, and
            // this is the same URL the SPA already receives for the file
            // (MediaResource::$url), so the two cannot drift apart.
            publicUrl: $this->url(),
        );
    }
}
