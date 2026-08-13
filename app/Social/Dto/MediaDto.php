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
     * @param  string  $path  Absolute local filesystem path (or storage-disk-resolved path) to the media file.
     * @param  string  $mimeType  e.g. 'image/jpeg', 'video/mp4'.
     * @param  string|null  $altText  Accessibility description, when the network supports it.
     * @param  string|null  $remoteId  Provider-assigned handle returned by SupportsMedia::uploadMedia(), referenced when the post is created. Null before upload / for providers that don't pre-upload media.
     */
    public function __construct(
        public string $path,
        public string $mimeType,
        public ?string $altText = null,
        public ?string $remoteId = null,
    ) {}

    /**
     * Returns a new MediaDto identical to this one except for
     * $remoteId. MediaDto is immutable (final readonly), so this is
     * the only way to attach an upload result — used by
     * SupportsMedia::uploadMedia() implementations to hand back the
     * post-upload handle without mutating the caller's original DTO.
     */
    public function withRemoteId(string $remoteId): self
    {
        return new self($this->path, $this->mimeType, $this->altText, $remoteId);
    }
}
