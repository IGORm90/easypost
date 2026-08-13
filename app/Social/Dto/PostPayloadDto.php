<?php

namespace App\Social\Dto;

/**
 * One post's worth of content to publish. A thread/chain is an array of
 * these (`SocialProviderInterface::post()`'s `$chain` parameter) — the
 * first element is the root post, subsequent elements are replies to the
 * previous one, in order. A single (non-threaded) post is a chain of one.
 */
final readonly class PostPayloadDto
{
    /**
     * @param  string  $content  Post body text, already validated against the target's ProviderCapabilities::$maxCharacters.
     * @param  MediaDto[]  $media  Attachments for this post/segment.
     * @param  array<string, mixed>  $settings  Provider-specific per-post options (e.g. a poll, a content warning) that don't belong in the generic contract.
     */
    public function __construct(
        public string $content,
        public array $media = [],
        public array $settings = [],
    ) {}
}
