<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * DELETE /api/media/{id} refuses to remove a file that is still
 * attached to an unpublished `Post` segment — anywhere in the
 * organization, not just among the caller's own posts (see
 * MediaUploadService::delete()'s docblock). Deleting the underlying
 * object would silently break a post someone is still editing/queued to
 * publish. A `Post` already `PUBLISHED` does not block deletion — the
 * publish has already happened, so removing the library file no longer
 * changes anything about what was posted.
 *
 * MediaController maps this to a 409.
 */
class MediaInUseException extends RuntimeException
{
    public static function forUnpublishedPost(): self
    {
        return new self('This media is attached to an unpublished post and cannot be deleted.');
    }
}
