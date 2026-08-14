<?php

namespace App\Social\Providers;

use App\Social\Dto\MediaDto;
use App\Social\Exceptions\ProviderPermanentException;

/**
 * How a provider gets an attachment's bytes onto the wire.
 *
 * Every provider used to do `(string) file_get_contents($media->path)`,
 * which had two problems. The first was fatal and is what final-review
 * batch 2's Critical 3 was about: `$path` was a storage KEY, so the read
 * returned `false` and a zero-byte attachment went out. The second
 * survived that fix and is what this trait exists for: even reading a
 * REAL local path that way pulls the whole file into a PHP string, so
 * `MediaTempFiles`' streamed copy (which exists precisely so a 1 GB video
 * cannot blow up a queue worker) was undone one step later, at the
 * attach() call — the copy streamed, then the send buffered.
 *
 * Handing Guzzle an open handle instead lets its multipart/body stream
 * read the file in chunks as it writes it to the socket, so peak memory
 * no longer tracks the customer's file size on either side of the
 * hand-off. Guzzle closes the stream when the request is done.
 *
 * A file this provider cannot open is permanent, not transient: the
 * publishing pipeline has already produced and verified this local copy
 * (`MediaTempFiles::copy()`), so failing here means something is wrong
 * with the worker's own filesystem, and a retry re-reads the same path.
 */
trait ReadsMediaFiles
{
    /**
     * The multipart part headers an attachment must carry.
     *
     * `Content-Type` is set EXPLICITLY from the stored, sniffed mime type
     * — re-review round 2, finding 2. Guzzle only infers a part's content
     * type when the caller supplies none, and it infers it from the
     * FILENAME (`MimeType::fromFilename()`). Since batch 2 started sending
     * the customer's own filename, that inference became
     * customer-controlled: a genuine PNG uploaded as `photo` or
     * `photo.txt` would have gone out as `application/octet-stream` or
     * `text/plain`, which a network that dispatches on part type can
     * reject. Before that change the temp copy's extension happened to
     * guarantee the right answer — an accident, not a design. `mime_type`
     * on the media row is `finfo`-sniffed from the file's actual bytes at
     * upload time (MediaUploadService::sniffMime()), so it is both more
     * trustworthy than any filename and the one this application already
     * validated against its allow-list.
     *
     * @return array<string, string>
     */
    protected function fileHeaders(MediaDto $media): array
    {
        return ['Content-Type' => $media->mimeType];
    }

    /**
     * @return resource
     */
    protected function fileStream(MediaDto $media)
    {
        $stream = @fopen($media->path, 'rb');

        if ($stream === false) {
            throw new ProviderPermanentException('An attached file could not be read for upload.');
        }

        return $stream;
    }
}
