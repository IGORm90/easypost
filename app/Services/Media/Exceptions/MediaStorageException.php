<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * A write to the `media` disk (the original file, never a thumbnail —
 * see MediaUploadService::maybeStoreThumbnail(), which treats a failed
 * thumbnail write as best-effort and returns null instead of throwing)
 * did not actually happen: the bucket doesn't exist, a transient S3/
 * MinIO error, or anything else Flysystem reports as
 * `UnableToWriteFile`. Review finding 1 (CRITICAL): before this,
 * MediaUploadService::writeToDisk() discarded `writeStream()`'s boolean
 * return value entirely, and the `media` disk's `'throw' => false`
 * config made Flysystem swallow the failure and log it — the request
 * still answered 201 with a full MediaResource and a persisted `Media`
 * row pointing at an object that was never written. This exception (plus
 * `config/filesystems.php`'s `media` disk now being `'throw' => true`)
 * makes that failure loud instead: MediaController never gets a `Media`
 * instance to build a success response from, and nothing is persisted
 * (persist() only calls `Media::query()->create()` AFTER both writeToDisk()
 * calls have returned without throwing).
 *
 * MediaController maps this to a 500 — a storage failure is this
 * application's fault (or its infrastructure's), not the caller's
 * input, so it is not a 4xx.
 */
class MediaStorageException extends RuntimeException
{
    public static function forFailedWrite(): self
    {
        return new self('The file could not be stored.');
    }
}
