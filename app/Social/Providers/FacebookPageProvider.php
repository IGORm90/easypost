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
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Exceptions\UnknownProviderOptionException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Facebook Page adapter. OAuth 2.0 against ONE Meta app registered by
 * the operator (config('services.facebook_page')) — deliberately NOT
 * ManualTokenAuth and NOT DeclaresAuthFields, so `GET /api/providers`
 * reports `auth_type: 'oauth'` with an empty `auth_fields`, which is
 * what lets the SPA's fast-connect branch skip the connect modal
 * entirely (Channels/Index.vue::beginConnect()).
 *
 * PKCE (UsesOAuth2Pkce) is not required by Facebook Login's server-side
 * flow, but is used anyway — one authorization-code flow shared across
 * every Block 5 network rather than a bespoke one for the networks that
 * happen not to need it.
 *
 * Token strategy — the one thing worth getting right here: after OAuth,
 * Meta hands back a USER token, immediately exchanged for its long-lived
 * (~60 day) form and stored as `integrations.token`. A PAGE token is
 * never stored: it is derived from the user token via `GET
 * /me/accounts` and is only as long-lived as the user token it comes
 * from, so persisting it would be a second secret nobody tracks the
 * expiry of. `post()` resolves the page token fresh on every publish
 * from `settings['page_id']` — one extra Graph call per publish, and an
 * immediate, correct reaction the moment a user revokes Page access,
 * rather than a stale secret quietly failing later.
 *
 * `page_id` is a per-CHANNEL setting (DeclaresUpdatableSettings), not a
 * per-post one: which page this Facebook login publishes to is a
 * property of the connected channel, chosen once via the settings
 * picker `SupportsOptions::options('pages')` drives, same as any other
 * "pick from a per-account list" setting in this app.
 */
class FacebookPageProvider implements DeclaresUpdatableSettings, SocialProviderInterface, SupportsOptions, SupportsRefresh
{
    use BoundsHttpTimeouts, ReadsMediaFiles, UsesMetaGraph, UsesOAuth2Pkce;

    private const string AUTHORIZE_URL = 'https://www.facebook.com/v21.0/dialog/oauth';

    private const string SCOPES = 'pages_show_list,pages_manage_posts,pages_read_engagement';

    public function identifier(): string
    {
        return 'facebook-page';
    }

    public function name(): string
    {
        return 'Facebook Page';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 63_206,
            maxImages: 1,
            maxVideos: 0,
            supportsThreads: false,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/gif'],
            maxFileSizeBytes: 10_000_000,
            allowsMixedMedia: false,
            maxThreadLength: 1,
            supportedMarks: [],
            requiresMedia: false,
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
        return ['page_id' => ['required', 'string']];
    }

    /**
     * @return array<string, string>
     */
    public function settingOptions(): array
    {
        return ['page_id' => 'pages'];
    }

    /**
     * @param  array<string, string>  $context
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option, array $context = []): array
    {
        return match ($option) {
            'pages' => $this->pageOptions((string) $integration->token),
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
        // never legitimately be a resumed chain — a non-null anchor here
        // means something upstream broke the "one segment, one channel"
        // invariant, and publishing anyway would attach an unrelated
        // reply relationship this network does not model for pages.
        if ($payload->replyToReleaseId !== null) {
            throw new ProviderPermanentException("{$this->name()} posts cannot be attached to a previous post.");
        }

        $pageId = (string) ($integration->settings['page_id'] ?? '');

        if ($pageId === '') {
            throw new ProviderPermanentException("This {$this->name()} channel has no page selected; open its settings and choose a page.");
        }

        $pageToken = $this->pageAccessToken((string) $integration->token, $pageId);
        $media = $payload->media[0] ?? null;

        $result = $media !== null
            ? $this->publishPhoto($pageId, $pageToken, $payload, $media)
            : $this->publishFeed($pageId, $pageToken, $payload);

        return [0 => $result];
    }

    private function publishFeed(string $pageId, string $pageToken, PostPayloadDto $payload): PostResultDto
    {
        $body = $this->call(
            fn (): Response => $this->http()->asForm()->post($this->graphUrl("{$pageId}/feed"), [
                'message' => $payload->content,
                'access_token' => $pageToken,
            ]),
            'publishing a post',
        );

        return $this->resultFromId($body, 'post');
    }

    private function publishPhoto(string $pageId, string $pageToken, PostPayloadDto $payload, MediaDto $media): PostResultDto
    {
        $body = $this->call(
            fn (): Response => $this->uploadHttp()->asMultipart()->post($this->graphUrl("{$pageId}/photos"), [
                ['name' => 'caption', 'contents' => $payload->content],
                ['name' => 'access_token', 'contents' => $pageToken],
                [
                    'name' => 'source',
                    'contents' => $this->fileStream($media),
                    'filename' => $media->filename(),
                    'headers' => $this->fileHeaders($media),
                ],
            ]),
            'publishing a photo post',
        );

        // /photos returns { id, post_id } — post_id is the feed story's
        // id and what a customer would recognise as "the post"; id alone
        // identifies only the photo attachment.
        $releaseId = $body['post_id'] ?? $body['id'] ?? null;

        if (! is_string($releaseId) || $releaseId === '') {
            throw new ProviderPermanentException("The {$this->name()} API did not return a post id.");
        }

        return new PostResultDto(releaseId: $releaseId);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function resultFromId(array $body, string $context): PostResultDto
    {
        $id = $body['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new ProviderPermanentException("The {$this->name()} API did not return a {$context} id.");
        }

        return new PostResultDto(releaseId: $id);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function pageOptions(string $userToken): array
    {
        $body = $this->call(
            fn (): Response => $this->http()->get($this->graphUrl('me/accounts'), ['fields' => 'id,name', 'access_token' => $userToken]),
            'listing pages',
        );

        $data = $body['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        $options = [];

        foreach ($data as $page) {
            if (is_array($page) && is_string($page['id'] ?? null) && is_string($page['name'] ?? null)) {
                $options[] = ['value' => $page['id'], 'label' => $page['name']];
            }
        }

        return $options;
    }

    private function pageAccessToken(string $userToken, string $pageId): string
    {
        $body = $this->call(
            fn (): Response => $this->http()->get($this->graphUrl('me/accounts'), ['fields' => 'id,access_token', 'access_token' => $userToken]),
            'resolving the page access token',
        );

        $data = $body['data'] ?? [];

        if (is_array($data)) {
            foreach ($data as $page) {
                if (is_array($page) && ($page['id'] ?? null) === $pageId && is_string($page['access_token'] ?? null)) {
                    return $page['access_token'];
                }
            }
        }

        throw new ProviderPermanentException("This {$this->name()} channel's page is no longer accessible; reconnect or choose another page.");
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
     * Meta's short-lived, code-exchange access token lives about an
     * hour — every Meta-backed provider must swap it for the long-lived
     * (~60 day) form immediately, or the channel goes dead within the
     * hour with nothing noticing until a publish fails. The SAME
     * endpoint and grant also extend an already-long-lived token
     * (refresh() calls this with the current stored token instead of a
     * fresh short-lived one), which is why this takes any bearer token
     * rather than being named "exchange short-lived".
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

    private function requireConfig(string $key): string
    {
        $value = config("services.facebook_page.{$key}");

        if (! is_string($value) || $value === '') {
            throw new ProviderPermanentException("{$this->name()} is not configured: missing services.facebook_page.{$key}.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function call(Closure $call, string $context): array
    {
        $response = $this->send($call);

        if (! $response->successful()) {
            throw $this->metaErrorFor($response, $context);
        }

        $body = $response->json();

        /** @var array<string, mixed> */
        return is_array($body) ? $body : [];
    }

    /**
     * Deliberately does NOT attach the caught ConnectionException as
     * $previous, same as every other provider in this directory: its
     * message can echo request details (here, the bearer token appended
     * to the URL as `access_token`) depending on the underlying HTTP
     * client's error formatting.
     */
    private function send(Closure $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new ProviderTransientException("Network error while calling the {$this->name()} API.");
        }

        return $this->refuseRedirect($response, $this->name());
    }
}
