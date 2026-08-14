<?php

namespace App\Social\Dto;

/**
 * Outcome of a successful `SocialProviderInterface::post()` call.
 * Returned once per `post()` invocation even though a whole thread was
 * published — `$releaseId` identifies the root of the published chain.
 */
final readonly class PostResultDto
{
    /**
     * @param  string  $releaseId  The published post/message's id on the remote network. Persisted so re-publishing can be detected/avoided and so the post can later be looked up or deleted.
     * @param  string|null  $releaseUrl  Public URL to view the published post, when the network exposes one.
     */
    public function __construct(
        public string $releaseId,
        public ?string $releaseUrl = null,
    ) {}
}
