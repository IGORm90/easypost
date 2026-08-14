<?php

namespace App\Social\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Every outbound HTTP call a provider adapter makes starts here, so that
 * none of them can inherit Laravel's 30-second default timeout by
 * omission — final-review Critical 3.
 *
 * Why a publish is different from an ordinary API call: `PublishPostJob`
 * runs under `supervisor-publish`'s `timeout: 90` (`config/horizon.php`),
 * and `PublishService::publishChannel()` holds a database transaction —
 * including row locks on the segments being sent — open across the whole
 * provider call. A remote host that simply stops responding therefore
 * costs the full timeout per call, and Slack's media path alone is FOUR
 * HTTP calls per segment (`getUploadURLExternal`, the upload itself,
 * `completeUploadExternal`, `chat.postMessage`). At the 30-second
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
            ->timeout(self::HTTP_TIMEOUT_SECONDS);
    }

    protected function uploadHttp(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::HTTP_UPLOAD_TIMEOUT_SECONDS);
    }
}
