<?php

namespace App\Social\Dto;

/**
 * One media attachment (image or video) belonging to a PostPayloadDto.
 * Providers read the file from $path; nothing in the contract layer
 * touches the filesystem itself.
 */
final readonly class MediaDto
{
    /**
     * @param  string  $path  Absolute local filesystem path (or storage-disk-resolved path) to the media file.
     * @param  string  $mimeType  e.g. 'image/jpeg', 'video/mp4'.
     * @param  string|null  $altText  Accessibility description, when the network supports it.
     */
    public function __construct(
        public string $path,
        public string $mimeType,
        public ?string $altText = null,
    ) {}
}
