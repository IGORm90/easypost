<?php

namespace Tests\Support;

use App\Models\Integration;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use Illuminate\Support\Str;

/**
 * A real, honest SocialProviderInterface implementation registered
 * under the 'fake' identifier — testing environment only (see
 * config/social.php). Implements the interface directly (not by
 * extending a real provider) so any contract change breaks this loudly,
 * which is the point.
 *
 * Deliberately stateful and controllable: Task 30 (publishing pipeline)
 * and Task 37 drive every failure path — transient, permanent, auth,
 * rate-limit — through this class instead of mocking HTTP calls for a
 * real network. Configure it, then call the interface methods; nothing
 * here does real I/O.
 *
 * Usage:
 *   $provider = app(ProviderRegistry::class)->get('fake');
 *   $provider->failNextPostWith(new ProviderRateLimitException('slow down', 30));
 *   $provider->post($integration, [$payload]); // throws that exception once
 */
class FakeProvider implements SocialProviderInterface
{
    private ProviderCapabilities $capabilities;

    /** Exception to throw on the next call, then clear. Null = succeed. */
    private ?\Throwable $nextPostException = null;

    private ?\Throwable $nextAuthenticateException = null;

    /** @var list<PostPayloadDto[]> every chain ever passed to post(), in call order */
    private array $publishedChains = [];

    public function __construct()
    {
        $this->capabilities = new ProviderCapabilities(
            maxCharacters: 4096,
            maxImages: 10,
            maxVideos: 1,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'video/mp4'],
            maxFileSizeBytes: 50 * 1024 * 1024,
        );
    }

    public function identifier(): string
    {
        return 'fake';
    }

    public function name(): string
    {
        return 'Fake';
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->capabilities;
    }

    /**
     * Override the default capabilities for a test, e.g. to simulate a
     * network with no video support or a low character limit.
     */
    public function withCapabilities(ProviderCapabilities $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    public function generateAuthUrl(string $state, string $redirectUri): AuthUrlDto
    {
        return new AuthUrlDto(
            url: "https://fake.test/oauth/authorize?state={$state}&redirect_uri=".urlencode($redirectUri),
            extra: ['state' => $state],
        );
    }

    /**
     * Queue an exception for the next authenticate() call. Cleared
     * after it fires once, mirroring failNextPostWith().
     */
    public function failNextAuthenticateWith(\Throwable $exception): self
    {
        $this->nextAuthenticateException = $exception;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        if ($this->nextAuthenticateException !== null) {
            $exception = $this->nextAuthenticateException;
            $this->nextAuthenticateException = null;
            throw $exception;
        }

        return new AuthResultDto(
            internalId: (string) ($params['internal_id'] ?? Str::ulid()),
            name: (string) ($params['name'] ?? 'Fake Account'),
            token: 'fake-token-'.Str::random(16),
            refreshToken: 'fake-refresh-'.Str::random(16),
        );
    }

    /**
     * Queue an exception to be thrown on the next post() call instead
     * of succeeding. Cleared after it fires once, so a test can arrange
     * "fail once, then succeed on retry" by only calling this before
     * the first attempt.
     *
     * Typical use: new ProviderTransientException(...),
     * new ProviderPermanentException(...), new ProviderAuthException(...),
     * or new ProviderRateLimitException($message, $retryAfterSeconds).
     */
    public function failNextPostWith(\Throwable $exception): self
    {
        $this->nextPostException = $exception;

        return $this;
    }

    /** Convenience wrapper around failNextPostWith() for the common cases. */
    public function failNextPostTransiently(string $message = 'fake transient failure'): self
    {
        return $this->failNextPostWith(new ProviderTransientException($message));
    }

    public function failNextPostPermanently(string $message = 'fake permanent failure'): self
    {
        return $this->failNextPostWith(new ProviderPermanentException($message));
    }

    public function failNextPostWithAuthError(string $message = 'fake auth failure'): self
    {
        return $this->failNextPostWith(new ProviderAuthException($message));
    }

    public function failNextPostWithRateLimit(int $retryAfterSeconds, string $message = 'fake rate limit'): self
    {
        return $this->failNextPostWith(new ProviderRateLimitException($message, $retryAfterSeconds));
    }

    public function post(Integration $integration, array $chain): PostResultDto
    {
        if ($this->nextPostException !== null) {
            $exception = $this->nextPostException;
            $this->nextPostException = null;
            throw $exception;
        }

        $this->publishedChains[] = $chain;

        $releaseId = 'fake-release-'.Str::ulid();

        return new PostResultDto(
            releaseId: $releaseId,
            releaseUrl: "https://fake.test/posts/{$releaseId}",
        );
    }

    /**
     * @return list<PostPayloadDto[]> every chain successfully published
     *                                so far, in call order — lets a test
     *                                assert on what was actually sent.
     */
    public function publishedChains(): array
    {
        return $this->publishedChains;
    }

    /** Reset all queued failures and recorded calls between test cases. */
    public function reset(): self
    {
        $this->nextPostException = null;
        $this->nextAuthenticateException = null;
        $this->publishedChains = [];

        return $this;
    }
}
