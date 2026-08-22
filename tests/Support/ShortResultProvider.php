<?php

namespace Tests\Support;

use App\Models\Integration;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;
use Illuminate\Support\Str;

/**
 * A `SocialProviderInterface` implementation that violates the one thing
 * `SocialProviderInterface::post()`'s docblock guarantees on full
 * success: "the returned array has exactly the same length as $chain".
 * Registered under 'short_result' — test-only, wired per-test via
 * `config()->set('social.providers.short_result', self::class)`, never
 * in `config/social.php`.
 *
 * Exists solely to cover `PublishService::publishChannel()`'s
 * `array_key_exists()` guard around `$results[$index]` (review Minor 6):
 * a provider bug of exactly this shape must never throw inside the
 * publishing transaction (which would discard every `release_id` already
 * resolved from the short `$results` for a segment that IS already live
 * on the network) — it must instead be treated like a partial failure,
 * publishing what it can and leaving the rest `QUEUE` for a retry.
 */
class ShortResultProvider implements SocialProviderInterface
{
    public function identifier(): string
    {
        return 'short_result';
    }

    public function name(): string
    {
        return 'Short Result (test double)';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 4096,
            maxImages: 10,
            maxVideos: 1,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png'],
            maxFileSizeBytes: 50 * 1024 * 1024,
            allowsMixedMedia: true,
            maxThreadLength: null,
            supportedMarks: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        return new AuthUrlDto(url: 'https://short-result.test/oauth/authorize', extra: []);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        return new AuthResultDto(
            internalId: (string) Str::ulid(),
            name: 'Short Result Account',
            token: 'short-result-token',
        );
    }

    /**
     * Always returns one FEWER result than $chain has elements — the
     * last segment's index is simply missing, never thrown for.
     *
     * @param  array<int, PostPayloadDto>  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $results = [];

        foreach (array_slice($chain, 0, max(count($chain) - 1, 0), preserve_keys: true) as $index => $payload) {
            $releaseId = 'short-result-release-'.Str::ulid();
            $results[$index] = new PostResultDto(
                releaseId: $releaseId,
                releaseUrl: "https://short-result.test/posts/{$releaseId}",
            );
        }

        return $results;
    }
}
