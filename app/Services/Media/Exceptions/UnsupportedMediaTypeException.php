<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * The real, sniffed content type of an uploaded or imported file (see
 * MediaUploadService::sniffMime()) is not one of the five types this
 * application accepts. Never thrown based on a file extension or a
 * client-supplied `Content-Type` header — both are a claim, not a fact
 * (see MediaUploadService's docblock) — only on what `finfo` actually
 * read from the file's bytes.
 *
 * MediaController maps this to a 422.
 */
class UnsupportedMediaTypeException extends RuntimeException
{
    public static function forMime(string $mime): self
    {
        return new self("File type [{$mime}] is not supported.");
    }
}
