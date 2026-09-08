<?php

namespace App\Social\Providers;

use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderRateLimitException;
use Illuminate\Http\Client\Response;

/**
 * The one piece of the three Meta-backed providers (Facebook Page,
 * Instagram, Threads — Block 5) that is genuinely shared: how a Graph
 * API URL is built and how Graph's error envelope is decoded. Modelled
 * on MapsHttpErrors' split — a shared decision table, provider-specific
 * detection — because Meta's *detection* half (an error's shape) is
 * identical across all three, unlike the token-exchange step
 * (UsesOAuth2Pkce's docblock explains why that one stays out of shared
 * code: different hosts, different grant param names, different client
 * auth).
 *
 * Threads lives on a different host (`graph.threads.net`, not
 * `graph.facebook.com`) but answers with the same envelope shape, which
 * is why `graphHost()` is overridable rather than a fixed constant.
 */
trait UsesMetaGraph
{
    use MapsHttpErrors;

    /**
     * Threads versions its API independently of the main Graph API
     * (`v1.0`, not `v21.0`), so this is overridable the same way
     * `graphHost()` is. Not a class constant (PHPStan flags a trait
     * constant as unused in any composing class that overrides the one
     * method reading it, e.g. ThreadsProvider) — a literal default
     * here carries the same "one place to bump" property.
     */
    protected function graphVersion(): string
    {
        return 'v21.0';
    }

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

    /**
     * The display host a subclass' Graph calls target. Facebook Page and
     * Instagram share graph.facebook.com; ThreadsProvider overrides this
     * to graph.threads.net.
     */
    protected function graphHost(): string
    {
        return 'graph.facebook.com';
    }

    protected function graphUrl(string $path): string
    {
        return 'https://'.$this->graphHost().'/'.$this->graphVersion().'/'.ltrim($path, '/');
    }

    /**
     * Decodes Graph's `{"error": {message, type, code, error_subcode}}`
     * envelope and maps it onto the shared exception taxonomy.
     * Deliberately never includes Meta's own `message` in the returned
     * exception's text — see BoundsHttpTimeouts' docblock: that text
     * reaches `posts.error` and the API response verbatim, and Meta's
     * message text is not this app's to repeat to a customer.
     */
    protected function metaErrorFor(Response $response, string $context): ProviderException
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        $code = is_array($error) ? ($error['code'] ?? null) : null;
        $subcode = is_array($error) ? ($error['error_subcode'] ?? null) : null;
        $retryAfter = (int) $response->header('Retry-After');

        if (is_int($subcode) && in_array($subcode, self::AUTH_ERROR_SUBCODES, true)) {
            return new ProviderAuthException("The {$this->name()} API rejected the credential while {$context} (subcode {$subcode}).");
        }

        if (is_int($code) && in_array($code, self::RATE_LIMIT_CODES, true)) {
            return new ProviderRateLimitException("The {$this->name()} API rate-limited the request while {$context}.", $retryAfter);
        }

        return $this->exceptionForStatus(
            $response->status(),
            "The {$this->name()} API rejected the request while {$context} ({$response->status()}).",
            $retryAfter,
        );
    }
}
