<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * `MediaTempFiles::copy()` could not produce a readable local copy of a
 * stored file — a missing object, an unreadable disk, or a temp file that
 * could not be created or written.
 *
 * Carries the `Media` id only, never the storage key or any part of the
 * object's contents: `PublishService` turns this into a
 * `ProviderPermanentException` whose message reaches `posts.error` and,
 * through `PostResource`, the API.
 *
 * Deliberately fatal for the segment rather than "publish without the
 * attachment": a post that was composed with an image and goes out
 * without one is a wrong post, not a degraded one.
 */
class MediaTemporaryFileException extends RuntimeException
{
    public static function forMedia(string $mediaId): self
    {
        return new self("The attached file [{$mediaId}] could not be read from storage.");
    }
}
