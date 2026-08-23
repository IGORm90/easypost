<?php

namespace Tests\Support;

use App\Models\Integration;
use App\Social\Contracts\DeclaresPostSettings;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsOptions;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\UnknownProviderOptionException;

/**
 * A provider that declares per-post settings (Block 5.0.6), including one
 * field whose option list depends on another field's value — the shape
 * Reddit needs (a subreddit, a title, and flairs that only exist for the
 * chosen subreddit) without naming Reddit anywhere outside
 * app/Social/Providers.
 *
 * Kept out of config/social.php like every other test double here: that
 * file ships to production and must never reference an autoload-dev
 * class. Registered by the tests that need it.
 */
class PostSettingsFakeProvider implements DeclaresPostSettings, SocialProviderInterface, SupportsOptions
{
    public function identifier(): string
    {
        return 'post-settings-fake';
    }

    public function name(): string
    {
        return 'Fake (post settings)';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 40000,
            maxImages: 1,
            maxVideos: 0,
            supportsThreads: false,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg'],
            maxFileSizeBytes: 1_000_000,
            allowsMixedMedia: false,
            maxThreadLength: null,
            supportedMarks: [],
            requiresMedia: false,
        );
    }

    /**
     * @return list<array{key: string, type: 'text'|'textarea'|'select', required: bool, rules: list<string>, option?: string, dependsOn?: string}>
     */
    public function postSettings(): array
    {
        return [
            ['key' => 'topic', 'type' => 'select', 'required' => true, 'option' => 'topics', 'rules' => ['string', 'max:50']],
            ['key' => 'title', 'type' => 'text', 'required' => true, 'rules' => ['string', 'max:10']],
            ['key' => 'flavour', 'type' => 'select', 'required' => false, 'option' => 'flavours', 'dependsOn' => 'topic', 'rules' => ['string']],
        ];
    }

    /**
     * @param  array<string, string>  $context
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option, array $context = []): array
    {
        if ($option === 'topics') {
            return [
                ['value' => 'jazz', 'label' => 'Jazz'],
                ['value' => 'folk', 'label' => 'Folk'],
            ];
        }

        if ($option === 'flavours') {
            $topic = $context['topic'] ?? null;

            // No parent value, no list — deliberately an empty list and
            // not an error: the editor asks for this the moment the field
            // is rendered, before the customer has chosen anything.
            if ($topic === null || $topic === '') {
                return [];
            }

            return [
                ['value' => "{$topic}-smooth", 'label' => 'Smooth'],
                ['value' => "{$topic}-free", 'label' => 'Free'],
            ];
        }

        throw UnknownProviderOptionException::forOption($this->identifier(), $option);
    }

    /**
     * @return array<string, string>
     */
    public function settingOptions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        return new AuthUrlDto(url: "https://post-settings-fake.test/authorize?state={$state}");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        return new AuthResultDto(
            internalId: (string) ($params['internal_id'] ?? 'post-settings-account'),
            name: 'Fake Post-Settings Account',
            token: 'post-settings-token',
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
