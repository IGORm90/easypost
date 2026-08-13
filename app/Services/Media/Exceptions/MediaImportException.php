<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * POST /api/media/from-url could not complete — anything short of "the
 * bytes were downloaded and their sniffed mime is allowed" lands here:
 * an unsafe/malformed URL (per App\Support\SafeRemoteHost), a download
 * that exceeded the 100 MB cap (whether or not the server's declared
 * Content-Length agreed), the 30-second total budget running out, too
 * many redirect hops, or any other network-level failure. Deliberately
 * one exception type for all of these — see MediaController, which maps
 * every case to the same 422, and MediaUploadService::download()'s
 * docblock for why the byte-cap and the "was this actually a size
 * problem" distinction are decided before any of these factories are
 * called, not by inspecting the exception afterwards.
 */
class MediaImportException extends RuntimeException
{
    public static function forUnsafeUrl(): self
    {
        return new self('This URL is not safe to download from.');
    }

    public static function forSizeExceeded(): self
    {
        return new self('The file exceeds the 100 MB import limit.');
    }

    public static function forTimeout(): self
    {
        return new self('The download did not complete within 30 seconds.');
    }

    public static function forTooManyRedirects(): self
    {
        return new self('The URL redirected too many times.');
    }

    public static function forDownloadFailed(): self
    {
        return new self('The file could not be downloaded.');
    }
}
