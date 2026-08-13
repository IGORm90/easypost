<?php

namespace App\Social\Contracts;

use App\Models\Integration;
use App\Social\Dto\MediaDto;

/**
 * Optional capability: implement this on a provider whose network
 * requires (or benefits from) uploading a media file ahead of composing
 * the post itself — e.g. Mastodon's `/api/v2/media` endpoint returns an
 * attachment id that must exist before the status referencing it can be
 * created; Bluesky uploads a blob and the post references that blob;
 * Slack completes a file upload and the message references the file id.
 * Providers that simply attach raw files inline on post() (nothing to
 * pre-upload) don't implement it.
 *
 * This is distinct from ProviderCapabilities::$maxImages/$maxVideos,
 * which only declare *whether/how much* media a network accepts — not
 * *how* it gets there.
 *
 * Two-phase flow: Task 25's editor calls uploadMedia() as soon as the
 * user attaches a file (so upload failures — wrong codec, file too
 * large, network error — surface immediately during composition rather
 * than at publish time). uploadMedia() returns a new MediaDto — via
 * MediaDto::withRemoteId(), since MediaDto is immutable — carrying the
 * network's handle for the now-uploaded file in $remoteId. That
 * returned MediaDto (not the original one passed in) is what gets
 * stored on PostPayloadDto::$media; when the post is eventually
 * published, post() reads $remoteId to reference the already-uploaded
 * attachment instead of re-uploading it inline.
 */
interface SupportsMedia
{
    /**
     * Upload one media file ahead of posting. Returns a new MediaDto —
     * the same path/mimeType/altText, plus $remoteId populated via
     * MediaDto::withRemoteId() — that the caller must use in place of
     * the one passed in.
     *
     * Throws the same App\Social\Exceptions taxonomy as post() on
     * failure (e.g. ProviderPermanentException for an unsupported
     * codec, ProviderTransientException for a transient upload error).
     */
    public function uploadMedia(Integration $integration, MediaDto $media): MediaDto;
}
