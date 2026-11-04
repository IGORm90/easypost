<?php

namespace App\Services\Posts;

use App\Social\Dto\MediaDto;
use Throwable;

/**
 * What ChannelAttempt::prepare() got ready outside the lock: each segment's
 * media, copied to a local file and pre-uploaded where the network wants that,
 * the temporary files to discard afterwards, and the failure (if any) that
 * stopped it. A failure is carried, not thrown, because it is only meaningful
 * once the attempt is inside the lock and knows the segments are still ours to
 * fail.
 */
final readonly class PreparedChannel
{
    /**
     * @param  array<string, list<MediaDto>>  $media  keyed by post id
     * @param  list<string>  $temporaryFiles
     */
    public function __construct(
        public array $media = [],
        public array $temporaryFiles = [],
        public ?Throwable $failure = null,
    ) {}
}
