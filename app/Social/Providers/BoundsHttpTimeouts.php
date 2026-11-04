<?php

namespace App\Social\Providers;

use App\Social\Exceptions\ProviderPermanentException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Every outbound HTTP call a provider adapter makes starts here, so that
 * none of them can inherit Laravel's 30-second default timeout by
 * omission — final-review Critical 3 — and so that none of them can
 * follow a redirect nobody validated — final-review batch 2, Critical 2.
 *
 * ## Redirects are not followed, at all
 *
 * Laravel's default client follows up to five hops, across hosts,
 * permits an https→http downgrade, and (Guzzle) rewrites a 301/302/303
 * POST into a GET. `hostFor()`-style re-validation is therefore worth
 * nothing on its own: the provider validates the host it was configured
 * with, sends the request, and the client then quietly repeats that
 * request wherever the remote host points it — the reviewer confirmed a
 * cross-host redirect to a loopback port being followed by this
 * project's own client. An org admin who connects a channel on a host
 * they control needs only a 302 from the publish endpoint to
 * `http://169.254.169.254/…` to turn every scheduled publish into a
 * request against internal infrastructure — and the answer is not blind:
 * a provider's `errorFor()` puts the remote body into the exception
 * message, `PublishService::markAllError()` writes it to `posts.error`,
 * and `PostResource` returns it to the caller.
 *
 * So `allow_redirects` is off on both builders below, and `ProviderCall`
 * (the one place every provider call is sent from) turns any 3xx that comes
 * back into a `ProviderPermanentException` naming nothing but the status
 * code. Two deliberate choices:
 *
 *  - REFUSING rather than re-validating each `Location` and re-issuing:
 *    no API endpoint any of these five networks documents answers a
 *    correctly-addressed API call with a redirect, so a redirect here is
 *    either a misconfiguration or an attack, and neither is something to
 *    resolve automatically on the customer's behalf. (`MediaUploadService`
 *    genuinely must follow them — a user-supplied download URL routinely
 *    redirects — which is why it, alone, implements the re-validating
 *    hop loop.)
 *  - The exception message carries the STATUS and the network's name,
 *    never the `Location` header or any part of the response body: that
 *    message reaches `posts.error` and the API, and this defect is
 *    precisely about a remote host choosing what lands there.
 *
 * Permanent, not transient: a redirect will be returned again on every
 * retry, and it needs a human to look at the channel.
 *
 * Why a publish is different from an ordinary API call: `PublishPostJob`
 * runs under `supervisor-publish`'s `timeout: 90` (`config/horizon.php`),
 * and `PublishService::publishChannel()` holds a database transaction —
 * including row locks on the segments being sent — open across the whole
 * provider call. A remote host that simply stops responding therefore
 * costs the full timeout per call, and a media path can be several HTTP
 * calls per segment (ask for an upload url, upload, complete the upload,
 * post the message). At the 30-second
 * default, a single slow segment blows past the worker timeout, the
 * worker is SIGALRM-killed mid-transaction, and — before
 * `REDIS_QUEUE_RETRY_AFTER` was raised to 300 — a second worker was
 * already holding the same payload, ready to re-send whatever the first
 * had put on the network but rolled back locally. See
 * `docs/DEPLOYMENT.md`, "Queue timeouts", for the full interleaving.
 *
 * Two budgets, because they are genuinely different workloads:
 *
 *  - `http()` — a JSON/form API call. 15s is far beyond any healthy
 *    response from the five networks here and still leaves a 3-segment
 *    text thread (one call each) inside a fifth of the worker budget.
 *  - `uploadHttp()` — a media transfer, bounded by the customer's file
 *    size (up to `ProviderCapabilities::$maxFileSizeBytes`) rather than
 *    by the remote host's think time, so it gets a longer allowance.
 *
 * `connectTimeout()` is set on both and is the more important half for
 * the hung-host case: it bounds the TCP/TLS handshake specifically,
 * which is where an unreachable or blackholed host stalls, and it is NOT
 * implied by `timeout()`.
 *
 * These bounds shorten the worst case; they do not make it fit for an
 * arbitrarily long thread. A chain long enough to exceed the worker
 * timeout anyway is covered by `retry_after` (300) being well above it,
 * so the killed attempt is never running concurrently with its own
 * redelivery.
 */
trait BoundsHttpTimeouts
{
    private const int HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    private const int HTTP_TIMEOUT_SECONDS = 15;

    private const int HTTP_UPLOAD_TIMEOUT_SECONDS = 45;

    protected function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->withOptions(['allow_redirects' => false]);
    }

    protected function uploadHttp(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::HTTP_UPLOAD_TIMEOUT_SECONDS)
            ->withOptions(['allow_redirects' => false]);
    }
}
