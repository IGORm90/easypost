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
