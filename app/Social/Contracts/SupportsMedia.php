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
 * Two-phase flow, and WHO drives it: `PublishService::prepareMedia()`
 * calls uploadMedia() for every attachment on every segment it is about
 * to publish, immediately before `post()`, and threads the returned
 * MediaDto — via MediaDto::withRemoteId(), since MediaDto is immutable —
 * into the payload chain. `post()` then reads $remoteId to reference the
 * already-uploaded attachment instead of re-uploading it inline.
 *
 * This docblock used to say the post editor called uploadMedia() "as
 * soon as the user attaches a file", so upload failures would surface
 * during composition. No such call existed anywhere in the application —
 * that confidently-stated, entirely fictional caller is precisely what
 * final-review batch 2's Critical 3 was raised for (media published on
 * exactly zero of five networks), so the correction matters as much as
 * the code fix did. The real semantics now: an upload failure surfaces at
 * PUBLISH time, on the queue, and is settled by PublishService's normal
 * provider-failure machinery — permanent failures mark the segment
 * ERROR, transient ones retry. A composition-time pre-upload would be a
 * genuine improvement (the customer would learn about a rejected codec
 * while still at the keyboard) and is not implemented; do not describe
 * it here until it is.
 *
 * Implementations are handed a MediaDto whose $path is a REAL LOCAL FILE
 * (a temp copy the pipeline makes with MediaTempFiles), never a storage
 * key, and whose $originalFilename carries the name to send to the
 * network — see MediaDto::filename().
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
