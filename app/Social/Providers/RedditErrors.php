<?php

namespace App\Social\Providers;

use App\Social\Contracts\ProviderErrorAdapter;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use Illuminate\Http\Client\Response;

/**
 * How Reddit's HTTP failures map onto the shared exception taxonomy.
 * Status-only: Reddit's `RATELIMIT` error arrives INSIDE a 200 envelope,
 * so it is not an HTTP failure and is detected by the provider after a
 * successful call (RedditProvider::exceptionForErrors).
 *
 * Messages carry the status and the caller's context only, never
 * Reddit's own body text: that text reaches `posts.error` and the API
 * response verbatim.
 */
final class RedditErrors implements ProviderErrorAdapter
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
            "Reddit API error while {$context} ({$response->status()}).",
            (int) $response->header('Retry-After'),
        );
    }

    /**
     * On an auth call (token exchange/refresh, the profile fetch after
     * it) any rejection means the credential itself is no good, not that
     * "content" was invalid — except the two cases that are worth
     * retrying: a rate limit and a server error.
     */
    private function authErrorFor(Response $response, string $context): ProviderException
    {
        $status = $response->status();

        if ($status === 429) {
            return new ProviderRateLimitException("Reddit rate-limited {$context}.", (int) $response->header('Retry-After'));
        }

        if ($status >= 500) {
            return new ProviderTransientException("Reddit API error while {$context}.");
        }

        return new ProviderAuthException("Reddit rejected the request while {$context}.");
    }
}
