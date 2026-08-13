<?php

namespace App\Social\Dto;

/**
 * Static description of what a provider's network allows a post to
 * contain. Consumed by the post-validation service (Task 19) and the
 * post editor (Task 25) to answer "may this content go to this channel"
 * without ever asking the provider itself — so these fields must be
 * sufficient on their own, with no follow-up calls to the network, and
 * without Task 19/25 ever branching on `identifier()` (that would
 * violate the project's no-provider-specific-logic-outside-Providers
 * rule, which tests/Unit/Architecture/ProviderIsolationTest.php
 * enforces).
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
     * @param  bool  $allowsMixedMedia  Whether images and video may appear together in the same post. No universal default — varies enough across networks that every provider must state it explicitly.
     * @param  int|null  $maxThreadLength  Max number of segments in a thread (the $chain passed to post()); null = unbounded. No default for the same reason as $allowsMixedMedia: silently assuming "unbounded" for a network that actually caps it would let Task 19 pass through content that publishing then rejects.
     * @param  bool  $requiresAltText  Whether the network requires alt text on every media attachment. Defaults to false: the overwhelming majority of networks treat it as optional, so providers only need to override this when their network genuinely enforces it.
     */
    public function __construct(
        public int $maxCharacters,
        public int $maxImages,
        public int $maxVideos,
        public bool $supportsThreads,
        public bool $supportsScheduling,
        public array $allowedMimeTypes,
        public int $maxFileSizeBytes,
        public bool $allowsMixedMedia,
        public ?int $maxThreadLength,
        public bool $requiresAltText = false,
    ) {}
}
