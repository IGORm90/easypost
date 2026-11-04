<?php

namespace App\Social\Providers;

use App\Social\Contracts\ProviderErrorAdapter;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use Illuminate\Http\Client\Response;

/**
 * How Telegram's Bot API failures map onto the shared exception taxonomy.
 *
 * Success is the body's `ok: true`, not the HTTP status: a failure can
 * arrive over a 200, and the real error code then lives in the body
 * (`error_code`, with `parameters.retry_after` for a rate limit). The HTTP
 * status is only the fallback when the body has no code.
 */
final class TelegramErrors implements ProviderErrorAdapter
{
    use MapsHttpErrors;

    public function failed(Response $response): bool
    {
        $body = $response->json();

        return ! is_array($body) || ($body['ok'] ?? false) !== true;
    }

    public function errorFor(Response $response, string $context, bool $auth): ProviderException
    {
        $body = $response->json();
        $description = is_array($body) ? (string) ($body['description'] ?? 'unknown error') : 'unknown error';
        $errorCode = is_array($body) && isset($body['error_code']) ? (int) $body['error_code'] : $response->status();
        $retryAfter = is_array($body) ? (int) data_get($body, 'parameters.retry_after', 0) : 0;

        if ($auth) {
            return $this->authErrorFor($errorCode, $description, $retryAfter);
        }

        // Distinguishes a revoked/invalid token, a bot removed from the
        // chat, a malformed request and a rate limit as four different
        // outcomes, using the shared status table for the parts that
        // generalise (429, 5xx) and Telegram's own envelope for the hint.
        return $this->exceptionForStatus($errorCode, "Telegram API error while {$context}: {$description}", $retryAfter);
    }

    /**
     * authenticate()-context mapping: unlike a content call, every
     * non-transient rejection (400 bad chat id, 401 revoked token, 403 bot
     * never added to the chat, 404 chat not found) means the same thing
     * here -- these credentials do not work -- so they all become
     * ProviderAuthException rather than being split by status code. The
     * messages are fixed phrases: the only auth call is the chat check.
     */
    private function authErrorFor(int $errorCode, string $description, int $retryAfter): ProviderException
    {
        if ($errorCode === 429) {
            return new ProviderRateLimitException("Telegram rate-limited chat verification: {$description}", $retryAfter);
        }

        if ($errorCode >= 500) {
            return new ProviderTransientException("Telegram API error while verifying credentials: {$description}");
        }

        return new ProviderAuthException("Telegram rejected the provided bot token/chat id: {$description}");
    }
}
