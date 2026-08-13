<?php

namespace App\Social\Contracts;

use App\Models\Integration;
use App\Social\Dto\MediaDto;

/**
 * Optional capability: implement this on a provider whose network
 * requires (or benefits from) uploading a media file ahead of composing
 * the post itself — e.g. Mastodon's media-upload endpoint returns an
 * attachment id that must exist before the status referencing it can be
 * created. Providers that simply attach raw files inline on post()
 * (nothing to pre-upload) don't implement it.
 *
 * This is distinct from ProviderCapabilities::$maxImages/$maxVideos,
 * which only declare *whether/how much* media a network accepts — not
 * *how* it gets there. Task 25's editor calls uploadMedia() as soon as
 * the user attaches a file (so upload failures — wrong codec, file too
 * large, network error — surface immediately during composition rather
 * than at publish time), then passes the returned MediaDto through
 * PostPayloadDto::$media unchanged when the post is eventually
 * published.
 */
interface SupportsMedia
{
    /**
     * Upload one media file ahead of posting. Returns a MediaDto to use
     * in the eventual PostPayloadDto — typically the same DTO with
     * provider-specific bookkeeping (e.g. a remote attachment id) folded
     * into it via a subclass or wrapped value; the exact shape is up to
     * the provider as long as it remains a valid MediaDto.
     *
     * Throws the same App\Social\Exceptions taxonomy as post() on
     * failure (e.g. ProviderPermanentException for an unsupported
     * codec, ProviderTransientException for a transient upload error).
     */
    public function uploadMedia(Integration $integration, MediaDto $media): MediaDto;
}
