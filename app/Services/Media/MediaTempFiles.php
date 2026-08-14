<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\Media\Exceptions\MediaTemporaryFileException;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/**
 * Turns a `Media` row into a real local file a provider can actually
 * read, and cleans it up afterwards — final-review batch 2, Critical 3.
 *
 * Why this exists at all: `Media::$path` is a KEY on the `media` disk
 * (S3/MinIO in every real environment), never a local filesystem path —
 * `Media`'s own docblock says so — and `Media::toDto()` passes that key
 * straight into `MediaDto::$path`. Two providers then called
 * `file_get_contents($media->path)` on it: that returns `false`,
 * `(string) false` is `''`, and a ZERO-BYTE attachment was uploaded, with
 * no error anywhere. The other three refused to publish at all. Nothing
 * in `app/` had ever called `SupportsMedia::uploadMedia()`, so media
 * worked on exactly none of the five networks.
 *
 * `readStream()` + a chunked copy, not `get()`: a customer's video can be
 * up to `ProviderCapabilities::$maxFileSizeBytes` (1 GB on Slack), and
 * pulling that through a PHP string in a queue worker is how a publish
 * run OOMs. Streaming keeps peak memory at one chunk regardless of file
 * size, and gives providers a path they can `attach()`/`filesize()`
 * exactly like a local upload.
 *
 * Cleanup is the caller's job and must be in a `finally` — see
 * `PublishService::publishChannel()`, which discards every temp file it
 * asked for whether the publish succeeded, failed, or threw.
 */
class MediaTempFiles
{
    private const int COPY_CHUNK_BYTES = 1024 * 1024;

    /**
     * Copies $media's stored bytes into a fresh temp file and returns its
     * path. The temp filename keeps the stored key's extension so
     * providers that derive a filename from the path still send something
     * with a sensible extension — a network that sniffs `photo.tmp` as
     * "not an image" would otherwise reject a perfectly good file. The
     * name the CUSTOMER sees does not come from here at all: it travels on
     * `MediaDto::$originalFilename` (re-review finding 6), because a temp
     * path can never carry it faithfully.
     *
     * NOTHING is left on disk unless this method returns successfully —
     * re-review finding 1, proven live: `tempnam()` creates the file
     * immediately, and every throw below used to walk past it, so each
     * retry of a publish whose attachment could not be read leaked another
     * temp file (a partially-written one, if the failure came mid-copy, so
     * not even bounded to 0 bytes). The caller cannot clean these up: it
     * only ever learns about paths this method RETURNED. Hence the
     * `$succeeded` flag and the unlink in `finally`, rather than an unlink
     * repeated at each throw — a future early return would silently
     * reintroduce the leak.
     *
     * @throws MediaTemporaryFileException when the object cannot be read
     *                                     (missing key, storage outage, a
     *                                     local temp file that cannot be
     *                                     created). Never returns a path
     *                                     to an empty or partial file:
     *                                     that is exactly the failure mode
     *                                     this class exists to make
     *                                     impossible to publish.
     */
    public function copy(Media $media): string
    {
        $extension = pathinfo($media->path, PATHINFO_EXTENSION);
        $tmpPath = tempnam(sys_get_temp_dir(), 'publish-media-');

        if ($tmpPath === false) {
            throw MediaTemporaryFileException::forMedia($media->id);
        }

        if ($extension !== '') {
            $withExtension = $tmpPath.'.'.$extension;

            if (@rename($tmpPath, $withExtension)) {
                $tmpPath = $withExtension;
            }
        }

        $source = null;
        $target = null;
        $succeeded = false;

        try {
            $source = Storage::disk($media->disk)->readStream($media->path);
            $target = fopen($tmpPath, 'wb');

            if (! is_resource($source) || ! is_resource($target)) {
                throw MediaTemporaryFileException::forMedia($media->id);
            }

            while (! feof($source)) {
                $chunk = fread($source, self::COPY_CHUNK_BYTES);

                if ($chunk === false) {
                    throw MediaTemporaryFileException::forMedia($media->id);
                }

                if ($chunk !== '' && fwrite($target, $chunk) === false) {
                    throw MediaTemporaryFileException::forMedia($media->id);
                }
            }

            // Closed HERE, not only in the finally: the size check below
            // must see everything that was written, and on some drivers a
            // buffered tail is only flushed on close.
            fclose($target);
            $target = null;

            // An empty local copy is never publishable — and, since it is
            // exactly what the pre-fix code produced, it is checked rather
            // than assumed away.
            clearstatcache(true, $tmpPath);

            if (filesize($tmpPath) === 0) {
                throw MediaTemporaryFileException::forMedia($media->id);
            }

            $succeeded = true;
        } catch (FilesystemException) {
            throw MediaTemporaryFileException::forMedia($media->id);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
            }

            if (! $succeeded) {
                $this->discard([$tmpPath]);
            }
        }

        return $tmpPath;
    }

    /**
     * @param  list<string>  $paths
     */
    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
