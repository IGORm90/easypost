<?php

namespace App\Services\Posts;

use App\Models\Integration;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\ProviderRegistry;

/**
 * Decides whether a $chain of PostPayloadDto may be scheduled/published
 * to a given Integration's network. Every answer comes from that
 * network's ProviderCapabilities (resolved once, up front, via
 * ProviderRegistry) — this class never branches on
 * `$integration->provider_identifier` or a provider's `identifier()`,
 * per the project's Global Constraint enforced by
 * tests/Unit/Architecture/ProviderIsolationTest.php. Adding a new
 * provider never requires touching this file: it only needs an accurate
 * ProviderCapabilities.
 *
 * Pure: no DB writes, no HTTP, no queue dispatch. Reading
 * `$integration->provider_identifier` (a plain property, already loaded
 * on the model the caller passed in) and resolving the provider through
 * the container are the only things this class touches beyond plain
 * PHP — no other framework facade is used. Called twice in the pipeline:
 * once at scheduling time (Task 20) and again inside the publish job
 * (Task 30), so it must return the exact same verdict both times for the
 * same inputs.
 *
 * validate() never throws — see App\Exceptions\PostValidationException
 * for the wrapper callers can throw once they've decided a non-empty
 * result should abort the request/job.
 *
 * Per-segment vs. across-the-chain limits: $chain is a thread — each
 * PostPayloadDto is a distinct post/reply published individually on the
 * remote network (see PostPayloadDto's docblock). Every per-post limit
 * (maxCharacters, maxImages, maxVideos, allowedMimeTypes,
 * maxFileSizeBytes, allowsMixedMedia, requiresAltText) is therefore
 * checked per segment, never summed across the chain — summing would
 * reject legal threads (e.g. 3 segments x 2 images each on a
 * maxImages=4 network, which is fine since each segment only ever
 * carries 2). Only two things are inherently chain-wide and are checked
 * once per chain: supportsThreads (a chain of >1 segment is a thread at
 * all) and maxThreadLength (how many segments the thread itself may
 * contain).
 *
 * Character counting: uses mb_strlen($content, 'UTF-8'), i.e. counts
 * Unicode codepoints, not bytes (strlen() would badly over-count
 * Cyrillic and any other multi-byte script) and not grapheme clusters
 * (ext-intl's grapheme_strlen() is unavailable in this project's PHP
 * image — see task-19-report.md). This means a multi-codepoint emoji
 * built from a ZWJ sequence (e.g. a family emoji, which is several base
 * emoji joined by zero-width-joiner codepoints) counts as multiple
 * characters here, same as a plain multi-character string, even though
 * it renders as one glyph and some networks' own counters treat it as
 * one or two "characters" under their specific algorithm (e.g. X/Twitter's
 * weighted counting). A single non-ZWJ emoji (the overwhelming majority
 * of real-world usage) is one Unicode codepoint and is counted
 * correctly as 1. This is a deliberate, documented approximation: it is
 * conservative in the common case and the only inaccuracy is undercounting
 * ZWJ-sequence emoji relative to some networks' own weighting, not
 * Cyrillic/emoji in general.
 */
final class PostValidationService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * @param  PostPayloadDto[]  $chain
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    public function validate(Integration $integration, array $chain): array
    {
        $capabilities = $this->providers->get($integration->provider_identifier)->capabilities();

        $errors = [
            ...$this->validateChainShape($capabilities, $chain),
        ];

        foreach ($chain as $index => $segment) {
            $errors = [...$errors, ...$this->validateSegment($capabilities, $index, $segment)];
        }

        return $errors;
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    private function validateChainShape(ProviderCapabilities $capabilities, array $chain): array
    {
        $errors = [];
        $length = count($chain);

        if ($length > 1 && ! $capabilities->supportsThreads) {
            for ($index = 1; $index < $length; $index++) {
                $errors[] = $this->error(
                    $index,
                    'threads_not_supported',
                    "This network does not support threads; segment {$index} cannot be posted as a reply in a chain of {$length}.",
                    limit: 1,
                    actual: $length,
                );
            }
        }

        if ($capabilities->maxThreadLength !== null && $length > $capabilities->maxThreadLength) {
            for ($index = $capabilities->maxThreadLength; $index < $length; $index++) {
                $errors[] = $this->error(
                    $index,
                    'too_many_segments',
                    "Thread has {$length} segments, exceeding this network's maximum of {$capabilities->maxThreadLength}.",
                    limit: $capabilities->maxThreadLength,
                    actual: $length,
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    private function validateSegment(ProviderCapabilities $capabilities, int $index, PostPayloadDto $segment): array
    {
        $errors = [];

        $length = mb_strlen($segment->content, 'UTF-8');
        $imageCount = 0;
        $videoCount = 0;
        foreach ($segment->media as $media) {
            if (str_starts_with($media->mimeType, 'video/')) {
                $videoCount++;
            } else {
                $imageCount++;
            }
        }

        if (trim($segment->content) === '' && $segment->media === []) {
            $errors[] = $this->error(
                $index,
                'empty_content',
                'Post has no text content and no media attached.',
                limit: null,
                actual: null,
            );
        }

        if ($length > $capabilities->maxCharacters) {
            $errors[] = $this->error(
                $index,
                'content_too_long',
                "Content is {$length} characters, exceeding the {$capabilities->maxCharacters} character limit.",
                limit: $capabilities->maxCharacters,
                actual: $length,
            );
        }

        if ($imageCount > $capabilities->maxImages) {
            $errors[] = $this->error(
                $index,
                'too_many_images',
                "Post has {$imageCount} images, exceeding the maximum of {$capabilities->maxImages}.",
                limit: $capabilities->maxImages,
                actual: $imageCount,
            );
        }

        if ($videoCount > $capabilities->maxVideos) {
            $errors[] = $this->error(
                $index,
                'too_many_videos',
                "Post has {$videoCount} videos, exceeding the maximum of {$capabilities->maxVideos}.",
                limit: $capabilities->maxVideos,
                actual: $videoCount,
            );
        }

        if (! $capabilities->allowsMixedMedia && $imageCount > 0 && $videoCount > 0) {
            $errors[] = $this->error(
                $index,
                'mixed_media_not_supported',
                'This network does not allow images and video in the same post.',
                limit: null,
                actual: null,
            );
        }

        foreach ($segment->media as $media) {
            $errors = [...$errors, ...$this->validateMedia($capabilities, $index, $media)];
        }

        return $errors;
    }

    /**
     * @return list<array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}>
     */
    private function validateMedia(ProviderCapabilities $capabilities, int $index, MediaDto $media): array
    {
        $errors = [];

        if (! in_array($media->mimeType, $capabilities->allowedMimeTypes, true)) {
            $errors[] = $this->error(
                $index,
                'unsupported_mime',
                "Media type {$media->mimeType} is not supported by this network.",
                limit: implode(', ', $capabilities->allowedMimeTypes),
                actual: $media->mimeType,
            );
        }

        $size = $this->fileSize($media->path);
        if ($size !== null && $size > $capabilities->maxFileSizeBytes) {
            $errors[] = $this->error(
                $index,
                'file_too_large',
                "File is {$size} bytes, exceeding the {$capabilities->maxFileSizeBytes} byte limit.",
                limit: $capabilities->maxFileSizeBytes,
                actual: $size,
            );
        }

        if ($capabilities->requiresAltText && ($media->altText === null || trim($media->altText) === '')) {
            $errors[] = $this->error(
                $index,
                'missing_alt_text',
                'This network requires alt text on every media attachment.',
                limit: null,
                actual: null,
            );
        }

        return $errors;
    }

    /**
     * Reads the file's size straight off disk (no Storage facade —
     * MediaDto::$path is documented as "absolute local filesystem path
     * (or storage-disk-resolved path)", i.e. already resolved to
     * something plain PHP I/O can stat). Returns null — skipping the
     * file_too_large check entirely rather than guessing — when the
     * path doesn't resolve to a readable file, since MediaDto carries no
     * size field of its own to fall back on.
     */
    private function fileSize(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        return $size === false ? null : $size;
    }

    /**
     * @return array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}
     */
    private function error(int $index, string $code, string $message, int|string|null $limit, int|string|null $actual): array
    {
        return [
            'post_index' => $index,
            'code' => $code,
            'message' => $message,
            'limit' => $limit,
            'actual' => $actual,
        ];
    }
}
