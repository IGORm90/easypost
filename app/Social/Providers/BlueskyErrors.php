<?php

namespace App\Social\Providers;

use App\Social\Contracts\ProviderErrorAdapter;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use Illuminate\Http\Client\Response;

/**
 * How Bluesky's XRPC failures (`{"error": code, "message": text}`) map onto
 * the shared exception taxonomy. Unlike the Meta adapter, Bluesky's own
 * `message` (falling back to `error`) is repeated in the exception text.
 */
final class BlueskyErrors implements ProviderErrorAdapter
{
    use MapsHttpErrors;

    public function failed(Response $response): bool
    {
        return ! $response->successful();
    }

    public function errorFor(Response $response, string $context, bool $auth): ProviderException
    {
        return $auth ? $this->authErrorFor($response, $context) : $this->contentErrorFor($response, $context);
    }

    private function contentErrorFor(Response $response, string $context): ProviderException
    {
        return $this->exceptionForStatus(
            $response->status(),
            "Bluesky API error while {$context} ({$response->status()}): {$this->messageFrom($response)}",
            (int) $response->header('Retry-After'),
        );
    }

    /**
     * Used for createSession/refreshSession, where any rejection means the
     * credential itself is no good, not that "content" was invalid — except
     * the two cases worth retrying: a rate limit and a server error.
     */
    private function authErrorFor(Response $response, string $context): ProviderException
    {
        $message = $this->messageFrom($response);
        $status = $response->status();

        if ($status === 429) {
            return new ProviderRateLimitException("Bluesky rate-limited {$context}: {$message}", (int) $response->header('Retry-After'));
        }

        if ($status >= 500) {
            return new ProviderTransientException("Bluesky API error while {$context}: {$message}");
        }

        return new ProviderAuthException("Bluesky rejected the request while {$context}: {$message}");
    }

    private function messageFrom(Response $response): string
    {
        $body = $response->json();

        return is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? 'unknown error')
            : 'unknown error';
    }
}
