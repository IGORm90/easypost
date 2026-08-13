<?php

namespace App\Social\Dto;

/**
 * Static description of what a provider's network allows a post to
 * contain. Consumed by the post-validation service (Task 19) and the
 * post editor (Task 25) to answer "may this content go to this channel"
 * without ever asking the provider itself — so these fields must be
 * sufficient on their own, with no follow-up calls to the network.
 */
final readonly class ProviderCapabilities
{
    /**
     * @param  int  $maxCharacters  Hard cap on post body length, e.g. 4096.
     * @param  int  $maxImages  Max images per post; 0 = images unsupported.
     * @param  int  $maxVideos  Max videos per post; 0 = video unsupported.
     * @param  bool  $supportsThreads  Whether a chain of >1 PostPayloadDto may be posted as a thread.
     * @param  bool  $supportsScheduling  Whether the network itself can hold a future publish time (vs. this app always publishing immediately at the scheduled time).
     * @param  array<int, string>  $allowedMimeTypes  e.g. ['image/jpeg','image/png','video/mp4'].
     * @param  int  $maxFileSizeBytes  Largest single media file the network accepts.
     */
    public function __construct(
        public int $maxCharacters,
        public int $maxImages,
        public int $maxVideos,
        public bool $supportsThreads,
        public bool $supportsScheduling,
        public array $allowedMimeTypes,
        public int $maxFileSizeBytes,
    ) {}
}
