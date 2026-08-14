<?php

namespace App\Social\Dto;

/**
 * One media attachment (image or video) belonging to a PostPayloadDto.
 * Providers read the file from $path; nothing in the contract layer
 * touches the filesystem itself.
 *
 * Two-phase upload (SupportsMedia): a MediaDto starts life with
 * $remoteId null. If the provider implements SupportsMedia,
 * uploadMedia() pre-uploads the file and returns a new MediaDto (via
 * withRemoteId()) carrying the network's handle for the uploaded
 * attachment — Mastodon's media-attachment id, a Bluesky blob
 * reference, a completed Slack file id, etc. That returned MediaDto is
 * what belongs in the eventual PostPayloadDto::$media; post() reads
 * $remoteId to reference the already-uploaded file instead of
 * re-uploading it inline.
 */
final readonly class MediaDto
{
    /**
     * @param  string  $path  Absolute local filesystem path (or storage-disk-resolved path) to the media file. Task 19 review (Finding 1): PostValidationService::validate() must NOT stat this path — it stopped working the moment $path becomes an S3/MinIO key (Task 27) instead of a local file, at which point every attachment would silently pass file-size validation. $sizeBytes below is the only source of truth for that check.
     * @param  string  $mimeType  e.g. 'image/jpeg', 'video/mp4'.
     * @param  int|null  $sizeBytes  File size in bytes, captured once by whatever creates this MediaDto (e.g. Task 27/28's upload/attachment code) — never re-derived later by reading $path off disk, since $path's meaning (local file vs. remote storage key) is not this DTO's concern. Null means "not known" and is a deliberate, distinct case: PostValidationService treats a null $sizeBytes as a validation failure (`file_size_unknown`), never as "skip the check" / "assume it's fine" — see that class's docblock for why silently treating unknown as valid was rejected.
     * @param  string|null  $altText  Accessibility description, when the network supports it.
     * @param  string|null  $remoteId  Provider-assigned handle returned by SupportsMedia::uploadMedia(), referenced when the post is created. Null before upload / for providers that don't pre-upload media.
     * @param  string|null  $originalFilename  The name the customer uploaded the file under (`media.original_filename`, already sanitized at upload time). Providers must send THIS as the attachment filename — see filename(). Null means "no better name known", and filename() falls back to the path's basename.
     */
    public function __construct(
        public string $path,
        public string $mimeType,
        public ?int $sizeBytes = null,
        public ?string $altText = null,
        public ?string $remoteId = null,
        public ?string $originalFilename = null,
    ) {}

    /**
     * The filename to send to a network. Re-review finding 6: providers
     * used to derive this from `basename($media->path)`, which — once the
     * publishing pipeline started handing them a LOCAL TEMP COPY — meant
     * every attachment reached the customer's timeline named
     * `publish-media-XXXXXX.png`. Slack in particular shows that name to
     * users. The stored `original_filename` is what belongs there; the
     * basename fallback keeps a hand-built DTO (a provider's own unit
     * test, say) working.
     */
    public function filename(): string
    {
        $name = $this->originalFilename !== null ? basename($this->originalFilename) : '';

        // Re-review round 2, finding 1: this value goes straight into a
        // multipart `Content-Disposition` header that Guzzle builds with
        // sprintf() and does not escape, so a `"` in it appends
        // attacker-chosen parameters to that header. MediaUploadService
        // now strips these at upload time, but rows stored BEFORE that
        // fix are still in the database and this is the single point every
        // provider reads — so the guarantee is enforced here too rather
        // than assumed to have happened earlier.
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $name);
        $name = trim($name);

        // Capped like MediaUploadService::sanitizeFilename() does at
        // storage time (re-review round 3, minor): in practice the column
        // bounds this already, but the two places that produce a filename
        // for the wire should not disagree about its limits.
        $name = mb_substr($name, 0, 255);

        return $name !== '' ? $name : basename($this->path);
    }

    /**
     * Returns a new MediaDto identical to this one except for
     * $remoteId. MediaDto is immutable (final readonly), so this is
     * the only way to attach an upload result — used by
     * SupportsMedia::uploadMedia() implementations to hand back the
     * post-upload handle without mutating the caller's original DTO.
     */
    public function withRemoteId(string $remoteId): self
    {
        return new self($this->path, $this->mimeType, $this->sizeBytes, $this->altText, $remoteId, $this->originalFilename);
    }

    /**
     * Returns a new MediaDto pointing at $path instead of this one's.
     * Used by the publishing pipeline (final-review batch 2, Critical 3):
     * `Media::toDto()` carries the STORAGE KEY, which no provider can
     * read — `PublishService` swaps in the path of a real local copy
     * (see `MediaTempFiles`) before any provider is handed the DTO, and
     * $sizeBytes deliberately still comes from the `media` row rather
     * than from that copy, per this class's "captured once" rule.
     */
    public function withPath(string $path): self
    {
        return new self($path, $this->mimeType, $this->sizeBytes, $this->altText, $this->remoteId, $this->originalFilename);
    }
}
