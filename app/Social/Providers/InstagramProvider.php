<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\DeclaresUpdatableSettings;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsOptions;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\UnknownProviderOptionException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Instagram (Business) adapter. OAuth 2.0 against ONE Meta app
 * registered by the operator (config('services.instagram')) —
 * deliberately NOT ManualTokenAuth and NOT DeclaresAuthFields, so `GET
 * /api/providers` reports `auth_type: 'oauth'` with an empty
 * `auth_fields`, which is what lets the SPA's fast-connect branch skip
 * the connect modal entirely (Channels/Index.vue::beginConnect()).
 *
 * Depends on a Facebook Page (README's "Obtaining credentials" section):
 * an Instagram Business account only exists in the Graph API attached to
 * a Facebook Page, so this provider authenticates exactly like
 * FacebookPageProvider — same dialog, same long-lived-token exchange —
 * requesting `pages_show_list` in addition to Instagram's own scopes
 * purely to enumerate the pages an account picker needs.
 *
 * `ig_user_id` is a per-CHANNEL setting (DeclaresUpdatableSettings): the
 * Instagram Business Account id chosen from `SupportsOptions::
 * options('accounts')`, which lists every page's LINKED Instagram
 * account, not the pages themselves — a page without one linked is
 * simply not offered as a choice.
 *
 * Publishing (PublishesViaMetaContainers): this network never accepts an
 * upload — every post starts as a container built from
 * MediaDto::$publicUrl (`image_url`/`video_url`), so `SupportsMedia` is
 * NOT implemented here — there is nothing to pre-upload independent of
 * the caption the container also needs, so the whole two-call sequence
 * lives inside post(). `requiresMedia: true` on capabilities() reflects
 * a hard Graph API rule, not a preference: Instagram refuses to publish
 * a container with no image/video at all.
 */
class InstagramProvider implements DeclaresUpdatableSettings, SocialProviderInterface, SupportsOptions, SupportsRefresh
{
    use BoundsHttpTimeouts, PublishesViaMetaContainers, UsesMetaGraph, UsesOAuth2Pkce;

    private const string AUTHORIZE_URL = 'https://www.facebook.com/v21.0/dialog/oauth';

    private const string SCOPES = 'pages_show_list,instagram_basic,instagram_content_publish';

    public function identifier(): string
    {
        return 'instagram';
    }

    public function name(): string
    {
        return 'Instagram';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 2_200,
            maxImages: 10,
            maxVideos: 1,
            supportsThreads: false,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'video/mp4'],
            maxFileSizeBytes: 100_000_000,
            allowsMixedMedia: false,
            maxThreadLength: 1,
            supportedMarks: [],
            requiresMedia: true,
            requiresAltText: false,
        );
    }

    /**
     * @param  array<string, mixed>  $params  Unused: nothing must be chosen before the redirect for this provider.
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        $verifier = $this->pkceVerifier();

        $query = http_build_query([
            'client_id' => $this->requireConfig('client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'code_challenge' => $this->pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        return new AuthUrlDto(url: self::AUTHORIZE_URL.'?'.$query, extra: ['code_verifier' => $verifier]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        $code = $params['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new ProviderAuthException("{$this->name()} did not return an authorization code.");
        }

        $verifier = $params['code_verifier'] ?? null;

        $body = $this->call(
            fn (): Response => $this->http()->get($this->graphUrl('oauth/access_token'), array_filter([
                'client_id' => $this->requireConfig('client_id'),
                'client_secret' => $this->requireConfig('client_secret'),
                'redirect_uri' => $redirectUri,
                'code' => $code,
                'code_verifier' => is_string($verifier) && $verifier !== '' ? $verifier : null,
            ])),
            'exchanging the authorization code',
        );

        $shortLivedToken = $body['access_token'] ?? null;

        if (! is_string($shortLivedToken) || $shortLivedToken === '') {
            throw new ProviderAuthException("{$this->name()} did not return an access token.");
        }

        return $this->authResultFromShortLivedToken($shortLivedToken);
    }

    public function refresh(Integration $integration): AuthResultDto
    {
        $currentToken = (string) $integration->token;

        if ($currentToken === '') {
            throw new ProviderAuthException("This {$this->name()} channel has no token; it must be reconnected.");
        }

        $body = $this->exchangeForLongLivedToken($currentToken);
        $token = $body['access_token'] ?? null;
        $expiresIn = $body['expires_in'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new ProviderAuthException("{$this->name()} refused to extend the token.");
        }

        return new AuthResultDto(
            internalId: (string) $integration->internal_id,
            name: (string) $integration->name,
            token: $token,
            expiresAt: is_int($expiresIn) || is_float($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function updatableSettings(): array
    {
        return ['ig_user_id' => ['required', 'string']];
    }

    /**
     * @return array<string, string>
     */
    public function settingOptions(): array
    {
        return ['ig_user_id' => 'accounts'];
    }

    /**
     * @param  array<string, string>  $context
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option, array $context = []): array
    {
        return match ($option) {
            'accounts' => $this->accountOptions((string) $integration->token),
            default => throw UnknownProviderOptionException::forOption($this->identifier(), $option),
        };
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $payload = $chain[0] ?? null;

        if ($payload === null) {
            throw new ProviderPermanentException('Nothing to publish.');
        }

        // supportsThreads: false and maxThreadLength: 1 mean this can
        // never legitimately be a resumed chain — see FacebookPageProvider
        // for the same guard and the same reasoning.
        if ($payload->replyToReleaseId !== null) {
            throw new ProviderPermanentException("{$this->name()} posts cannot be attached to a previous post.");
        }

        $igUserId = (string) ($integration->settings['ig_user_id'] ?? '');

        if ($igUserId === '') {
            throw new ProviderPermanentException("This {$this->name()} channel has no account selected; open its settings and choose an Instagram account.");
        }

        $media = $payload->media;

        if ($media === []) {
            // Belt-and-braces: PostValidationService's requiresMedia
            // check should have already rejected this at schedule time
            // (ProviderCapabilities::$requiresMedia above), but post()
            // must not assume validation upstream always ran.
            throw new ProviderPermanentException("{$this->name()} does not publish a post without an image or video.");
        }

        $token = (string) $integration->token;

        $creationId = count($media) === 1
            ? $this->createSingleContainer($igUserId, $token, $payload->content, $media[0])
            : $this->createCarouselContainer($igUserId, $token, $payload->content, $media);

        $this->awaitContainer($token, $creationId);
        $releaseId = $this->publishContainer('media', $igUserId, $token, $creationId);

        return [0 => new PostResultDto(releaseId: $releaseId)];
    }

    private function createSingleContainer(string $igUserId, string $token, string $caption, MediaDto $media): string
    {
        $isVideo = str_starts_with($media->mimeType, 'video/');

        $params = $isVideo
            ? ['media_type' => 'VIDEO', 'video_url' => $this->requirePublicUrl($media), 'caption' => $caption]
            : ['image_url' => $this->requirePublicUrl($media), 'caption' => $caption];

        return $this->createContainer('media', $igUserId, $token, $params);
    }

    /**
     * @param  MediaDto[]  $media
     */
    private function createCarouselContainer(string $igUserId, string $token, string $caption, array $media): string
    {
        $childIds = [];

        foreach ($media as $item) {
            $childIds[] = $this->createContainer('media', $igUserId, $token, [
                'image_url' => $this->requirePublicUrl($item),
                'is_carousel_item' => 'true',
            ]);
        }

        return $this->createContainer('media', $igUserId, $token, [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $caption,
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function accountOptions(string $userToken): array
    {
        $body = $this->call(
            fn (): Response => $this->http()->get($this->graphUrl('me/accounts'), [
                'fields' => 'name,instagram_business_account{id,username}',
                'access_token' => $userToken,
            ]),
            'listing linked Instagram accounts',
        );

        $data = $body['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        $options = [];

        foreach ($data as $page) {
            $account = is_array($page) ? ($page['instagram_business_account'] ?? null) : null;

            if (is_array($account) && is_string($account['id'] ?? null) && is_string($account['username'] ?? null)) {
                $options[] = ['value' => $account['id'], 'label' => '@'.$account['username']];
            }
        }

        return $options;
    }

    private function authResultFromShortLivedToken(string $shortLivedToken): AuthResultDto
    {
        $body = $this->exchangeForLongLivedToken($shortLivedToken);
        $token = $body['access_token'] ?? null;
        $expiresIn = $body['expires_in'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new ProviderAuthException("{$this->name()} did not return a long-lived access token.");
        }

        $me = $this->call(
            fn (): Response => $this->http()->get($this->graphUrl('me'), ['fields' => 'id,name', 'access_token' => $token]),
            'fetching the account profile',
        );

        $id = $me['id'] ?? null;
        $name = $me['name'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
            throw new ProviderAuthException("{$this->name()} did not return an account id/name.");
        }

        return new AuthResultDto(
            internalId: $id,
            name: $name,
            token: $token,
            expiresAt: is_int($expiresIn) || is_float($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
        );
    }

    /**
     * See FacebookPageProvider::exchangeForLongLivedToken() — same
     * endpoint, same grant, same "also used to extend an
     * already-long-lived token" duality.
     *
     * @return array<string, mixed>
     */
    private function exchangeForLongLivedToken(string $token): array
    {
        return $this->call(
            fn (): Response => $this->http()->get($this->graphUrl('oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->requireConfig('client_id'),
                'client_secret' => $this->requireConfig('client_secret'),
                'fb_exchange_token' => $token,
            ]),
            'exchanging the long-lived token',
        );
    }

    protected function configPrefix(): string
    {
        return 'services.instagram';
    }
}
