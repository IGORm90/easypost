<?php

namespace Tests\Support;

use App\Models\Integration;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsOptions;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Exceptions\UnknownProviderOptionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * A real, honest SocialProviderInterface implementation registered
 * under the 'fake' identifier — testing environment only, wired in
 * Tests\TestCase::setUp() (never in config/social.php, which stays
 * production-clean). Implements the interface directly (not by
 * extending a real provider) so any contract change breaks this loudly,
 * which is the point.
 *
 * Deliberately stateful and controllable: Task 30 (publishing pipeline)
 * and Task 37 drive every failure path — transient, permanent, auth,
 * rate-limit, partial-failure, and thread-resumption — through this
 * class instead of mocking HTTP calls for a real network. Configure it,
 * then call the interface methods; nothing here does real I/O.
 *
 * Usage:
 *   $provider = app(ProviderRegistry::class)->get('fake');
 *   $provider->failNextPostWith(new ProviderRateLimitException('slow down', 30));
 *   $provider->post($integration, [$payload]); // throws that exception once
 */
class FakeProvider implements SocialProviderInterface, SupportsOptions, SupportsRefresh
{
    private ProviderCapabilities $capabilities;

    /** Exception to throw on the next call, then clear. Null = succeed. */
    private ?Throwable $nextPostException = null;

    private ?Throwable $nextAuthenticateException = null;

    /**
     * Queued for the next refresh() call, then cleared — mirrors
     * $nextAuthenticateException. Task 16's RefreshIntegrationTokenJob
     * tests drive every refresh outcome (success, ProviderAuthException,
     * ProviderTransientException, ProviderRateLimitException) through
     * this instead of a real network.
     */
    private ?Throwable $nextRefreshException = null;

    /** @var list<string> integration ids refresh() was called for, in call order. */
    private array $refreshedIntegrationIds = [];

    /** @var array{succeedCount: int, message: string}|null */
    private ?array $nextPartialFailure = null;

    /**
     * When true, post() throws ProviderPermanentException instead of
     * honouring $chain[0]->replyToReleaseId — simulates a network that
     * cannot reply to an arbitrary external post id, per
     * PostPayloadDto's documented contract for that case.
     */
    private bool $refusesReplyToReleaseId = false;

    /** @var list<PostPayloadDto[]> every chain fully published by post(), in call order */
    private array $publishedChains = [];

    /**
     * Content-keyed, NON-one-shot behaviours applied by post() — see
     * onPostContaining(). Unlike $nextPostException (which fires on
     * whichever call happens to come first), these select by what is
     * actually being sent, so a test driving a multi-channel group
     * through the real PublishService can say "channel A fails, channel
     * B is slow" without depending on the order publishChannel() happens
     * to visit channels in.
     *
     * @var array<string, array{sleepMicroseconds: int, exception: ?Throwable}>
     */
    private array $contentBehaviours = [];

    /**
     * Every segment ever successfully published by post(), across all
     * calls (including segments published just before a partial
     * failure), in publish order. Each entry records whether it was
     * published as a fresh thread root or attached to an existing post
     * — either another segment published earlier in the same call, or
     * (for the first segment of a resumed chain) the given
     * $replyToReleaseId. This is what lets a test prove a resumed
     * thread was actually threaded rather than double-rooted.
     *
     * @var list<array{releaseId: string, attachedTo: ?string}>
     */
    private array $publishedSegments = [];

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
            allowsMixedMedia: true,
            maxThreadLength: null,
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

    /**
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
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
    public function failNextAuthenticateWith(Throwable $exception): self
    {
        $this->nextAuthenticateException = $exception;

        return $this;
    }

    /** Queue an exception for the next refresh() call. Cleared after it fires once. */
    public function failNextRefreshWith(Throwable $exception): self
    {
        $this->nextRefreshException = $exception;

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
     * SupportsRefresh implementation: returns a fresh token pair by
     * default, or throws whatever failNextRefreshWith() queued.
     */
    public function refresh(Integration $i): AuthResultDto
    {
        $this->refreshedIntegrationIds[] = $i->id;

        if ($this->nextRefreshException !== null) {
            $exception = $this->nextRefreshException;
            $this->nextRefreshException = null;
            throw $exception;
        }

        return new AuthResultDto(
            internalId: $i->internal_id,
            name: $i->name,
            token: 'fake-refreshed-token-'.Str::random(16),
            refreshToken: 'fake-refreshed-refresh-'.Str::random(16),
            expiresAt: CarbonImmutable::now()->addHour(),
        );
    }

    /** @return list<string> */
    public function refreshedIntegrationIds(): array
    {
        return $this->refreshedIntegrationIds;
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
     * For a partial-thread failure, use failNextPostWithPartialFailure()
     * instead — it needs to know how many chain segments to simulate as
     * having already succeeded.
     */
    public function failNextPostWith(Throwable $exception): self
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

    /**
     * Queue a partial-thread failure for the next post() call: the
     * first $succeedCount elements of the chain passed to post() are
     * treated as having published successfully (each gets a real
     * PostResultDto), then a ProviderPartialFailureException is thrown
     * carrying those results. Cleared after it fires once.
     */
    public function failNextPostWithPartialFailure(int $succeedCount, string $message = 'fake partial failure'): self
    {
        $this->nextPartialFailure = ['succeedCount' => $succeedCount, 'message' => $message];

        return $this;
    }

    /**
     * Make post() throw ProviderPermanentException whenever it's given
     * a chain whose first element carries $replyToReleaseId, simulating
     * a network that cannot reply to an arbitrary external post id —
     * exercises the "provider must refuse rather than duplicate" branch
     * of PostPayloadDto's resumption contract.
     */
    public function refuseReplyToReleaseId(bool $refuse = true): self
    {
        $this->refusesReplyToReleaseId = $refuse;

        return $this;
    }

    /**
     * Applies, on EVERY post() call whose chain contains a segment whose
     * content contains $needle: first a real `usleep($sleepMicroseconds)`
     * INSIDE post() — i.e. while `PublishService::publishChannel()`'s
     * transaction is open and its row locks are held, which is exactly
     * the window every concurrency race in this pipeline lives in — then
     * $exception, if one was given.
     *
     * Selecting by content rather than by call order is what lets a
     * fork-based test drive a genuinely multi-channel group through the
     * real production path: `PublishService::loadChains()` decides which
     * channel is attempted first, and a test must not have to guess.
     */
    public function onPostContaining(string $needle, ?Throwable $exception = null, int $sleepMicroseconds = 0): self
    {
        $this->contentBehaviours[$needle] = [
            'sleepMicroseconds' => $sleepMicroseconds,
            'exception' => $exception,
        ];

        return $this;
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $this->applyContentBehaviour($chain);

        if ($this->nextPostException !== null) {
            $exception = $this->nextPostException;
            $this->nextPostException = null;
            throw $exception;
        }

        $anchor = $chain[0]->replyToReleaseId ?? null;

        if ($anchor !== null && $this->refusesReplyToReleaseId) {
            throw new ProviderPermanentException(
                'fake provider cannot reply to an external post id ['.$anchor.']',
            );
        }

        if ($this->nextPartialFailure !== null) {
            $config = $this->nextPartialFailure;
            $this->nextPartialFailure = null;

            $published = [];
            $previousReleaseId = null;
            foreach (array_slice($chain, 0, $config['succeedCount'], preserve_keys: true) as $index => $payload) {
                $attachedTo = $index === 0 ? $payload->replyToReleaseId : $previousReleaseId;
                $result = $this->fakeResult($attachedTo);
                $published[$index] = $result;
                $previousReleaseId = $result->releaseId;
            }

            throw new ProviderPartialFailureException($config['message'], $published);
        }

        $results = [];
        $previousReleaseId = null;
        foreach ($chain as $index => $payload) {
            $attachedTo = $index === 0 ? $payload->replyToReleaseId : $previousReleaseId;
            $result = $this->fakeResult($attachedTo);
            $results[$index] = $result;
            $previousReleaseId = $result->releaseId;
        }

        $this->publishedChains[] = $chain;

        return $results;
    }

    /**
     * @param  PostPayloadDto[]  $chain
     */
    private function applyContentBehaviour(array $chain): void
    {
        foreach ($this->contentBehaviours as $needle => $behaviour) {
            foreach ($chain as $payload) {
                if (! str_contains($payload->content, $needle)) {
                    continue;
                }

                if ($behaviour['sleepMicroseconds'] > 0) {
                    usleep($behaviour['sleepMicroseconds']);
                }

                if ($behaviour['exception'] !== null) {
                    throw $behaviour['exception'];
                }

                return;
            }
        }
    }

    private function fakeResult(?string $attachedTo): PostResultDto
    {
        $releaseId = 'fake-release-'.Str::ulid();

        $this->publishedSegments[] = ['releaseId' => $releaseId, 'attachedTo' => $attachedTo];

        return new PostResultDto(
            releaseId: $releaseId,
            releaseUrl: "https://fake.test/posts/{$releaseId}",
        );
    }

    /**
     * @return list<PostPayloadDto[]> every chain fully published so far,
     *                                in call order — lets a test assert
     *                                on what was actually sent. Chains
     *                                that ended in
     *                                ProviderPartialFailureException are
     *                                NOT recorded here; inspect the
     *                                exception's $published property for
     *                                those instead.
     */
    public function publishedChains(): array
    {
        return $this->publishedChains;
    }

    /**
     * Every segment successfully published across all post() calls,
     * including ones that preceded a partial failure.
     *
     * @return list<array{releaseId: string, attachedTo: ?string}>
     */
    public function publishedSegments(): array
    {
        return $this->publishedSegments;
    }

    /**
     * Release ids of every segment published as a fresh thread root
     * (attachedTo === null) — i.e. neither threaded to a prior segment
     * within the same call nor resuming via $replyToReleaseId. A test
     * asserting "no duplicate root was published" during a resumed
     * thread checks that this stays at exactly one entry.
     *
     * @return list<string>
     */
    public function rootReleaseIds(): array
    {
        return array_values(array_map(
            fn (array $segment): string => $segment['releaseId'],
            array_filter($this->publishedSegments, fn (array $segment): bool => $segment['attachedTo'] === null),
        ));
    }

    /**
     * SupportsOptions implementation, exercised by Task 13's generic
     * `GET /api/integrations/{id}/options/{option}` endpoint tests: a
     * canned 'rooms' list, and UnknownProviderOptionException for
     * anything else — proving the controller stays generic (it never
     * hardcodes 'rooms' anywhere) while still exercising both the happy
     * path and the "provider doesn't recognise this option" 404 path.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option): array
    {
        if ($option !== 'rooms') {
            throw UnknownProviderOptionException::forOption($this->identifier(), $option);
        }

        return [
            ['value' => 'general', 'label' => 'General'],
            ['value' => 'random', 'label' => 'Random'],
        ];
    }

    /** Reset all queued failures and recorded calls between test cases. */
    public function reset(): self
    {
        $this->nextPostException = null;
        $this->nextAuthenticateException = null;
        $this->nextRefreshException = null;
        $this->nextPartialFailure = null;
        $this->contentBehaviours = [];
        $this->refusesReplyToReleaseId = false;
        $this->publishedChains = [];
        $this->publishedSegments = [];
        $this->refreshedIntegrationIds = [];

        return $this;
    }
}
