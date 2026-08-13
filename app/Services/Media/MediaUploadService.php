<?php

namespace App\Services\Media;

use App\Enums\PostState;
use App\Models\Media;
use App\Models\Organization;
use App\Services\Media\Exceptions\MediaImportException;
use App\Services\Media\Exceptions\MediaInUseException;
use App\Services\Media\Exceptions\UnsupportedMediaTypeException;
use App\Support\Exceptions\UnsafeRemoteHostException;
use App\Support\SafeRemoteHost;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything that turns "a file, from somewhere" into a stored `Media`
 * row (Task 27): direct multipart upload (store()) and server-side
 * import by URL (storeFromUrl()). Both funnel through the same
 * validation and storage path (persist()) so the two entry points can
 * never drift on what counts as an allowed type or where a file ends
 * up.
 *
 * ## Why files never execute
 *
 * Every stored file (and its thumbnail) lives under `org/{org_id}/...`
 * on the `media` disk (S3/MinIO in every real environment, a fake local
 * disk in tests — see config/filesystems.php) — never under this
 * application's `public`/`local` disks, which sit inside the same
 * filesystem tree nginx and PHP-FPM serve application code from. S3/MinIO
 * is an object store, not a script interpreter: it returns the exact
 * bytes written, with the Content-Type this service set at write time
 * (see persist()'s `mimetype` option below) — it does not inspect an
 * object's extension or content to decide whether to "run" it, because
 * object storage has no such concept. A `.php`-named or
 * PHP-code-containing object stored there is inert; there is no path
 * from "this file exists on the media disk" to "this file's contents
 * execute as code on this server or any other," which is the actual
 * property `store()`/`storeFromUrl()` need to guarantee, not merely
 * "reject files that look dangerous."
 *
 * ## Why mime-sniffing (not extension/Content-Type) is not sufficient by
 * itself
 *
 * sniffMime() reads the file's magic bytes via `finfo` — the actual
 * content, never the client-supplied `Content-Type` header
 * (`UploadedFile::getClientMimeType()` is never called anywhere in this
 * class) and never the filename's extension. This proves the file's
 * *header* matches an allowed type; it does NOT prove the file contains
 * nothing else. A polyglot — bytes that are simultaneously a valid GIF
 * (or JPEG/PNG) from byte 0 and something else entirely later in the
 * stream (a validly-embedded `<script>`, a ZIP central directory, a PHP
 * tag) — sails through `finfo` exactly as clean as a genuine photo does,
 * because `finfo` (like `getimagesize()`) reads only enough of the file
 * to identify its type; it never validates that the ENTIRE file is
 * nothing but that type. This is exactly why "never served from a path
 * where it could execute" above is this class's real defense, not mime
 * sniffing: sniffing keeps out the obviously-wrong-type file (an .exe
 * renamed to .jpg, caught because its real magic bytes aren't any
 * allowed type's); it does not, and cannot, prove a passing file is
 * *safe to execute* — so nothing here or downstream is ever allowed to
 * execute it, regardless of how clean the sniffed mime looks.
 */
final class MediaUploadService
{
    /**
     * Real, sniffed mime type => file extension used when building the
     * storage key. The extension comes from THIS map, never from the
     * client-supplied filename — an upload named `photo.jpg.php` whose
     * real content sniffs as image/jpeg is stored as `{ulid}.jpg`, not
     * `{ulid}.jpg.php` or anything else derived from client input.
     *
     * @var array<string, string>
     */
    public const array ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
    ];

    /**
     * Fallback default (100 MB) used only if config/media.php's
     * `import_max_bytes` is somehow unset — the real, live value always
     * comes from config() (see importMaxBytes()) so an operator can
     * retune it, and so tests can exercise the size-exceeded branch
     * without generating a genuine 100+ MB fixture.
     */
    private const int DEFAULT_IMPORT_MAX_BYTES = 100 * 1024 * 1024;

    private const int DEFAULT_IMPORT_TIMEOUT_SECONDS = 30;

    private const int DEFAULT_IMPORT_MAX_REDIRECTS = 5;

    private const int DEFAULT_THUMBNAIL_WIDTH = 400;

    public function store(UploadedFile $file, Organization $organization, ?string $createdBy = null): Media
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw UnsupportedMediaTypeException::forMime('unknown');
        }

        $mime = self::sniffMime($realPath);
        $this->assertAllowedMime($mime);

        return $this->persist(
            localPath: $realPath,
            mime: $mime,
            sizeBytes: (int) $file->getSize(),
            originalFilename: $file->getClientOriginalName(),
            organization: $organization,
            createdBy: $createdBy,
        );
    }

    /**
     * Downloads $url server-side and stores it exactly like a direct
     * upload. See download()'s docblock for the SSRF/redirect/size/
     * timeout defenses applied before a single byte is trusted.
     */
    public function storeFromUrl(string $url, Organization $organization, ?string $createdBy = null): Media
    {
        $localPath = $this->download($url);

        try {
            $mime = self::sniffMime($localPath);
            $this->assertAllowedMime($mime);

            $sizeBytes = filesize($localPath);

            if ($sizeBytes === false) {
                throw MediaImportException::forDownloadFailed();
            }

            $originalFilename = basename((string) parse_url($url, PHP_URL_PATH));

            return $this->persist(
                localPath: $localPath,
                mime: $mime,
                sizeBytes: $sizeBytes,
                originalFilename: $originalFilename !== '' ? $originalFilename : 'download',
                organization: $organization,
                createdBy: $createdBy,
            );
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * Removes a Media row and its stored objects (original + thumbnail,
     * if any). Refuses (MediaInUseException, mapped by MediaController
     * to a 409) if the file is attached to any `Post` segment that
     * isn't PUBLISHED yet — deliberately unfiltered by who created that
     * post: `$media->posts()` only ever returns rows already scoped to
     * this organization (Post uses BelongsToOrganization, and this
     * method always runs with the caller's organization active), so the
     * check already spans the WHOLE organization's posts, not just ones
     * the deleting user happens to own. A PUBLISHED post does not block
     * deletion — see MediaInUseException's docblock.
     */
    public function delete(Media $media): void
    {
        $usedByUnpublishedPost = $media->posts()
            ->where('state', '!=', PostState::PUBLISHED->value)
            ->exists();

        if ($usedByUnpublishedPost) {
            throw MediaInUseException::forUnpublishedPost();
        }

        $paths = array_values(array_filter([$media->path, $media->thumbnail_path]));

        if ($paths !== []) {
            Storage::disk($media->disk)->delete($paths);
        }

        $media->delete();
    }

    /**
     * Real content-based mime detection (`finfo`, reading $localPath's
     * actual bytes) — never the client-supplied Content-Type header and
     * never a filename extension. Shared by both entry points above AND
     * UploadMediaRequest (so the request-validation layer rejects a
     * disallowed type using the exact same sniff this service uses to
     * decide what to store, rather than a second, potentially
     * diverging, implementation).
     */
    public static function sniffMime(string $localPath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $localPath);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function assertAllowedMime(string $mime): void
    {
        if (! array_key_exists($mime, self::ALLOWED_MIME_TYPES)) {
            throw UnsupportedMediaTypeException::forMime($mime);
        }
    }

    /**
     * Downloads $url into a local temp file, enforcing every guarantee
     * POST /api/media/from-url promises:
     *
     * - SSRF: SafeRemoteHost::assertSafeUrl($currentUrl, ['http',
     *   'https']) runs on EVERY hop of a redirect chain, not just the
     *   URL the caller originally submitted — `allow_redirects` is
     *   turned off deliberately (see below) specifically so a redirect
     *   from a safe host to an internal one is re-validated exactly
     *   like the original URL, rather than followed blindly by the
     *   HTTP client.
     * - Response size lying about itself: the cap is enforced via a
     *   cURL progress callback that aborts the transfer the moment
     *   actual downloaded bytes exceed IMPORT_MAX_BYTES — this reads
     *   real bytes received, never the response's (attacker-controlled,
     *   possibly absent or wrong) Content-Length header. The final size
     *   used for the stored row is likewise `filesize()` on what
     *   actually landed on disk (see storeFromUrl()), never a header
     *   value.
     * - Slow-loris: `Http::timeout()` is cURL's total-transfer timeout
     *   (connect + read combined), so a server that dribbles bytes
     *   slowly enough to never trip a single "read" timeout is still
     *   cut off once the WHOLE request has run for IMPORT_TIMEOUT_SECONDS.
     *   $deadline additionally makes that a budget for the entire
     *   redirect chain, not per hop — a chain of N redirects, each
     *   waiting just under the per-hop timeout, cannot multiply a
     *   worker's total hold time past ~30s. Repeated abuse (many
     *   separate requests, each capped at 30s) is bounded at the route
     *   layer instead — see routes/api.php's `throttle` on this route.
     *
     * Every early-return path unlinks its own temp file; only the
     * caller's eventually-returned path is left on disk (storeFromUrl()
     * unlinks that one too, once it's been read and persisted).
     */
    private function download(string $url): string
    {
        $maxBytes = $this->importMaxBytes();
        $deadline = microtime(true) + $this->importTimeoutSeconds();
        $currentUrl = $url;

        for ($hop = 0; $hop <= $this->importMaxRedirects(); $hop++) {
            try {
                SafeRemoteHost::assertSafeUrl($currentUrl, ['http', 'https']);
            } catch (UnsafeRemoteHostException) {
                throw MediaImportException::forUnsafeUrl();
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw MediaImportException::forTimeout();
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'media-import-');

            if ($tmpPath === false) {
                throw MediaImportException::forDownloadFailed();
            }

            $sink = fopen($tmpPath, 'w+b');

            if ($sink === false) {
                @unlink($tmpPath);

                throw MediaImportException::forDownloadFailed();
            }

            try {
                // Broad catch deliberately: a network boundary call can
                // fail in many distinct ways (DNS, connect refused, our
                // own progress-callback abort, transfer timeout) and
                // every one of them is handled identically below by
                // inspecting how many bytes actually made it to disk —
                // there is no case here where a narrower catch would
                // change the outcome, only one where it would risk an
                // uncaught exception turning into an unhandled 500
                // instead of the 422 this endpoint promises.
                $response = Http::timeout((int) ceil($remaining))
                    ->connectTimeout(min(10, max(1, (int) ceil($remaining))))
                    ->withOptions([
                        'sink' => $sink,
                        'allow_redirects' => false,
                        'curl' => [
                            CURLOPT_NOPROGRESS => false,
                            CURLOPT_PROGRESSFUNCTION => static fn ($resource, $downloadSize, $downloaded): int => $downloaded > $maxBytes ? 1 : 0,
                        ],
                    ])
                    ->get($currentUrl);
            } catch (\Throwable $e) {
                if (is_resource($sink)) {
                    fclose($sink);
                }

                $downloadedBytes = @filesize($tmpPath) ?: 0;
                @unlink($tmpPath);

                if ($downloadedBytes > $maxBytes) {
                    throw MediaImportException::forSizeExceeded();
                }

                if ($e instanceof ConnectionException) {
                    throw MediaImportException::forTimeout();
                }

                throw MediaImportException::forDownloadFailed();
            }

            if (is_resource($sink)) {
                fclose($sink);
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                @unlink($tmpPath);

                $location = $response->header('Location');

                if ($location === '') {
                    throw MediaImportException::forDownloadFailed();
                }

                $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                @unlink($tmpPath);

                throw MediaImportException::forDownloadFailed();
            }

            $actualBytes = filesize($tmpPath);

            if ($actualBytes === false || $actualBytes > $maxBytes) {
                @unlink($tmpPath);

                throw MediaImportException::forSizeExceeded();
            }

            if ($actualBytes === 0) {
                @unlink($tmpPath);

                throw MediaImportException::forDownloadFailed();
            }

            return $tmpPath;
        }

        throw MediaImportException::forTooManyRedirects();
    }

    private function persist(
        string $localPath,
        string $mime,
        int $sizeBytes,
        string $originalFilename,
        Organization $organization,
        ?string $createdBy,
    ): Media {
        $ulid = (string) Str::ulid();
        $extension = self::ALLOWED_MIME_TYPES[$mime];
        $path = "org/{$organization->id}/{$ulid}.{$extension}";

        $this->writeToDisk($path, $localPath, $mime);

        $thumbnailPath = $this->maybeStoreThumbnail($localPath, $mime, $organization->id, $ulid, $extension);

        /** @var Media */
        return Media::query()->create([
            'organization_id' => $organization->id,
            'disk' => 'media',
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $mime,
            'size_bytes' => $sizeBytes,
            'original_filename' => $this->sanitizeFilename($originalFilename),
            'created_by' => $createdBy,
        ]);
    }

    private function writeToDisk(string $key, string $localPath, string $mime): void
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw UnsupportedMediaTypeException::forMime($mime);
        }

        try {
            Storage::disk('media')->writeStream($key, $stream, [
                'visibility' => 'public',
                'mimetype' => $mime,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Best-effort: images only, and only when GD can actually decode
     * the file. A failure here (missing GD, corrupt/unsupported image
     * data GD can't parse despite finfo recognising its header) never
     * fails the upload — it just means no thumbnail is stored, and
     * MediaResource's `thumbnail_url` falls back to null (Task 29's UI
     * is expected to fall back to the full image).
     */
    private function maybeStoreThumbnail(string $localPath, string $mime, string $organizationId, string $ulid, string $extension): ?string
    {
        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        $thumbTmpPath = $this->makeThumbnail($localPath, $mime);

        if ($thumbTmpPath === null) {
            return null;
        }

        try {
            $key = "org/{$organizationId}/{$ulid}_thumb.{$extension}";
            $this->writeToDisk($key, $thumbTmpPath, $mime);

            return $key;
        } finally {
            @unlink($thumbTmpPath);
        }
    }

    private function makeThumbnail(string $path, string $mime): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image instanceof \GdImage) {
            return null;
        }

        $width = imagesx($image);
        $thumbnailWidth = $this->thumbnailWidth();

        // No upscaling: an image already at or below the thumbnail
        // width is its own thumbnail (thumbnail_path stays null;
        // MediaResource's consumer falls back to the original url).
        // imagesx()/imagesy() are guaranteed positive for a real
        // GdImage, so only the width-vs-target comparison is meaningful
        // here (a zero/negative dimension is not a real case PHPStan's
        // stubs allow for this type).
        if ($width <= $thumbnailWidth) {
            imagedestroy($image);

            return null;
        }

        $thumbnail = imagescale($image, $thumbnailWidth);
        imagedestroy($image);

        if (! $thumbnail instanceof \GdImage) {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'media-thumb-');

        if ($tmpPath === false) {
            imagedestroy($thumbnail);

            return null;
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($thumbnail, $tmpPath, 82),
            'image/png' => imagepng($thumbnail, $tmpPath),
            'image/gif' => imagegif($thumbnail, $tmpPath),
            'image/webp' => imagewebp($thumbnail, $tmpPath, 82),
            default => false,
        };

        imagedestroy($thumbnail);

        if (! $ok) {
            @unlink($tmpPath);

            return null;
        }

        return $tmpPath;
    }

    private function importMaxBytes(): int
    {
        return (int) config('media.import_max_bytes', self::DEFAULT_IMPORT_MAX_BYTES);
    }

    private function importTimeoutSeconds(): int
    {
        return (int) config('media.import_timeout_seconds', self::DEFAULT_IMPORT_TIMEOUT_SECONDS);
    }

    private function importMaxRedirects(): int
    {
        return (int) config('media.import_max_redirects', self::DEFAULT_IMPORT_MAX_REDIRECTS);
    }

    private function thumbnailWidth(): int
    {
        return (int) config('media.thumbnail_width', self::DEFAULT_THUMBNAIL_WIDTH);
    }

    /**
     * Filenames are user input (the client-supplied upload name, or the
     * last path segment of an imported URL) that MediaResource echoes
     * back and that could reach a `Content-Disposition` header down the
     * line (e.g. a future signed-download endpoint) — sanitized once,
     * here, before it's ever persisted: `basename()` strips any
     * directory component (so it can never smuggle a path), control
     * characters (including CR/LF, which is what would let a filename
     * inject an extra header line) are stripped outright, and the
     * result is capped at 255 characters.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'file' : mb_substr($name, 0, 255);
    }
}
