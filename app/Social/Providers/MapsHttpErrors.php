<?php

namespace App\Social\Providers;

use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;

/**
 * Shared HTTP-status -> exception taxonomy mapping used by every
 * provider adapter in this directory. This is the part of the
 * error-handling story that genuinely does not vary by network: an HTTP
 * 429 always means "the caller must wait", a 5xx always means "try
 * again later", a 401/403 always means "this credential no longer
 * works". What DOES vary by network -- how to detect that condition in
 * the first place (Telegram reports errors as `{"ok": false, ...}` with
 * its own `error_code` inside a 200 or a matching HTTP status, encodes
 * the rate-limit wait in `parameters.retry_after`; Mastodon uses plain
 * HTTP status codes and a `Retry-After` header) -- stays in each
 * provider, which computes a status code and a retry-after value in
 * whatever way its network actually reports them and hands both to
 * exceptionForStatus() here. Keeping that split (shared decision table,
 * provider-specific detection) is what task-14-brief.md asks for, and
 * is what lets Task 15's providers reuse this trait instead of
 * reinventing the mapping.
 */
trait MapsHttpErrors
{
    /**
     * Translate an HTTP status code (plus a retry-after hint, when
     * known) into the App\Social\Exceptions taxonomy. Never returns a
     * raw HTTP exception -- always one of the five ProviderException
     * implementations, per SocialProviderInterface::post()'s contract.
     *
     * $message must already be safe to log/display: callers are
     * responsible for keeping tokens and other credentials out of it
     * (see this project's Global Constraint that tokens must never
     * appear in exception messages).
     */
    protected function exceptionForStatus(int $status, string $message, int $retryAfterSeconds = 0): ProviderException
    {
        return match (true) {
            $status === 429 => new ProviderRateLimitException($message, max(0, $retryAfterSeconds)),
            $status === 401 || $status === 403 => new ProviderAuthException($message),
            $status >= 500 => new ProviderTransientException($message),
            $status >= 400 => new ProviderPermanentException($message),
            // Anything below 400 reaching here means the caller decided
            // the response counts as a failure despite a non-error HTTP
            // status (e.g. Telegram's `{"ok": false}` envelope arriving
            // over a 200) -- treat as transient: nothing here indicates
            // the request itself was invalid.
            default => new ProviderTransientException($message),
        };
    }
}
