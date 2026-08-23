<?php

namespace Tests\Support;

use App\Models\Integration;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\ProviderCapabilities;

/**
 * A minimal SocialProviderInterface implementation that does NOT
 * implement SupportsOptions — the counterpart to FakeProvider, which
 * does. Exists solely so IntegrationApiTest can prove
 * `GET /api/integrations/{id}/options/{option}` 404s generically for a
 * provider with no options capability at all, not just for an
 * unrecognised option name on a provider that has some. Never wired into
 * config/social.php; registered ad hoc by the one test that needs it,
 * the same way FakeProvider is wired in Tests\TestCase.
 */
class NoOptionsFakeProvider implements SocialProviderInterface
{
    public function identifier(): string
    {
        return 'fake-no-options';
    }

    public function name(): string
    {
        return 'Fake (no options)';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 4096,
            maxImages: 0,
            maxVideos: 0,
            supportsThreads: false,
            supportsScheduling: false,
            allowedMimeTypes: [],
            maxFileSizeBytes: 0,
            allowsMixedMedia: false,
            maxThreadLength: null,
            supportedMarks: [],
            requiresMedia: false,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        return new AuthUrlDto(url: "https://fake-no-options.test/authorize?state={$state}");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        return new AuthResultDto(
            internalId: (string) ($params['internal_id'] ?? 'no-options-account'),
            name: 'Fake No-Options Account',
            token: 'fake-no-options-token',
        );
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, never>
     */
    public function post(Integration $integration, array $chain): array
    {
        return [];
    }
}
