<?php

namespace App\Social\Providers;

use App\Social\Contracts\ProviderErrorAdapter;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderRateLimitException;
use Illuminate\Http\Client\Response;

/**
 * How the three Meta-backed providers (Facebook Page, Instagram, Threads)
 * read Graph's `{"error": {message, type, code, error_subcode}}` envelope
 * and map it onto the shared exception taxonomy. Meta's *detection* half
 * (an error's shape) is identical across all three, which is why this is
 * one adapter and not three; the token-exchange step stays out of shared
 * code (UsesOAuth2Pkce's docblock explains why).
 *
 * Deliberately never includes Meta's own `message` in the returned
 * exception's text — see BoundsHttpTimeouts' docblock: that text reaches
 * `posts.error` and the API response verbatim, and Meta's message text is
 * not this app's to repeat to a customer.
 */
final class MetaGraphErrors implements ProviderErrorAdapter
{
    use MapsHttpErrors;

    /**
     * @var array<int, int> Error subcodes Meta uses for "this credential
     *                      no longer works" — expired/invalid session (190), app not
     *                      installed (102), password changed (460), token expired due to
     *                      re-authorization required (463), Business Manager permission
     *                      revoked (467). See Meta's "Error Codes for Login API".
     */
    private const array AUTH_ERROR_SUBCODES = [190, 102, 460, 463, 467];

    /**
     * @var array<int, int> Top-level error codes Meta uses for
     *                      application- and account-level rate limiting.
     */
    private const array RATE_LIMIT_CODES = [4, 17, 32, 613];

    public function __construct(private readonly string $network) {}

    public function failed(Response $response): bool
    {
        return ! $response->successful();
    }

    public function errorFor(Response $response, string $context, bool $auth): ProviderException
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        $code = is_array($error) ? ($error['code'] ?? null) : null;
        $subcode = is_array($error) ? ($error['error_subcode'] ?? null) : null;
        $retryAfter = (int) $response->header('Retry-After');

        if (is_int($subcode) && in_array($subcode, self::AUTH_ERROR_SUBCODES, true)) {
            return new ProviderAuthException("The {$this->network} API rejected the credential while {$context} (subcode {$subcode}).");
        }

        if (is_int($code) && in_array($code, self::RATE_LIMIT_CODES, true)) {
            return new ProviderRateLimitException("The {$this->network} API rate-limited the request while {$context}.", $retryAfter);
        }

        return $this->exceptionForStatus(
            $response->status(),
            "The {$this->network} API rejected the request while {$context} ({$response->status()}).",
            $retryAfter,
        );
    }
}
