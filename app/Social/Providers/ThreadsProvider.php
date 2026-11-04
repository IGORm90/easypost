<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Threads adapter. OAuth 2.0 against ONE Meta app registered by the
 * operator (config('services.meta_threads')) — deliberately NOT
 * ManualTokenAuth and NOT DeclaresAuthFields, so `GET /api/providers`
 * reports `auth_type: 'oauth'` with an empty `auth_fields`, which is
 * what lets the SPA's fast-connect branch skip the connect modal
 * entirely (Channels/Index.vue::beginConnect()).
 *
 * A separate app surface from the other two Meta providers: Threads
 * authorizes at `threads.net` (not `facebook.com`) and its Graph calls
 * target `graph.threads.net` on API version `v1.0` (not the main Graph
 * API's `v21.0`) — see UsesMetaGraph::graphHost()/graphVersion(). Same
 * error envelope shape, though, so UsesMetaGraph's decoding is reused
 * unchanged.
 *
 * Native threads (SocialProviderInterface::post()'s $chain contract):
 * unlike Facebook Page and Instagram, Threads models a reply chain
 * natively — `supportsThreads: true`, and $chain[0]->replyToReleaseId
 * (when resuming after a ProviderPartialFailureException) becomes that
 * segment's `reply_to_id`, never a fresh detached root. A later segment
 * always replies to the id the previous segment just published.
 *
 * Publishing (PublishesViaMetaContainers): like Instagram, Threads never
 * accepts an upload — a post is a container built from
 * MediaDto::$publicUrl, published in a second call. `requiresMedia:
 * false` here, unlike Instagram: Threads' container also accepts pure
 * text (`media_type: TEXT`), which is why the trait's requirePublicUrl()
 * is only reached when a segment actually carries an attachment.
 */
class ThreadsProvider implements SocialProviderInterface, SupportsRefresh
{
    use BoundsHttpTimeouts, PublishesViaMetaContainers, UsesMetaGraph, UsesOAuth2Pkce;

    private const string AUTHORIZE_URL = 'https://threads.net/oauth/authorize';

    private const string TOKEN_URL = 'https://graph.threads.net/oauth/access_token';

    /**
     * Deliberately NOT under the versioned graphUrl() path — Threads'
     * own long-lived-token exchange is documented at this bare host
     * path, unlike every other Graph call this provider makes.
     */
    private const string LONG_LIVED_TOKEN_URL = 'https://graph.threads.net/access_token';

    private const string SCOPES = 'threads_basic,threads_content_publish';

    public function identifier(): string
    {
        return 'meta-threads';
    }

    public function name(): string
    {
        return 'Threads';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 500,
            maxImages: 1,
            maxVideos: 1,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'video/mp4'],
            maxFileSizeBytes: 100_000_000,
            allowsMixedMedia: false,
            maxThreadLength: null,
            supportedMarks: [],
            requiresMedia: false,
            requiresAltText: false,
        );
    }

    protected function graphHost(): string
    {
        return 'graph.threads.net';
    }

    protected function graphVersion(): string
    {
        return 'v1.0';
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
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'state' => $state,
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
            fn (): Response => $this->http()->asForm()->post(self::TOKEN_URL, array_filter([
                'client_id' => $this->requireConfig('client_id'),
                'client_secret' => $this->requireConfig('client_secret'),
                'grant_type' => 'authorization_code',
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
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $token = (string) $integration->token;
        $threadsUserId = (string) $integration->internal_id;

        if ($threadsUserId === '') {
            throw new ProviderPermanentException("This {$this->name()} channel has no account id; it must be reconnected.");
        }

        $results = [];
        $previousReleaseId = null;

        foreach ($chain as $index => $payload) {
            $replyTo = $index === 0 ? $payload->replyToReleaseId : $previousReleaseId;

            try {
                $creationId = $this->createSegmentContainer($threadsUserId, $token, $payload, $replyTo);
                $this->awaitContainer($token, $creationId);
                $releaseId = $this->publishContainer('threads', $threadsUserId, $token, $creationId);
            } catch (ProviderException $e) {
                if ($results !== []) {
                    throw new ProviderPartialFailureException($e->getMessage(), $results, $e);
                }

                throw $e;
            }

            $results[$index] = new PostResultDto(releaseId: $releaseId);
            $previousReleaseId = $releaseId;
        }

        return $results;
    }

    private function createSegmentContainer(string $threadsUserId, string $token, PostPayloadDto $payload, ?string $replyTo): string
    {
        $media = $payload->media[0] ?? null;
        $params = ['text' => $payload->content];

        if ($media instanceof MediaDto) {
            $isVideo = str_starts_with($media->mimeType, 'video/');
            $params['media_type'] = $isVideo ? 'VIDEO' : 'IMAGE';
            $params[$isVideo ? 'video_url' : 'image_url'] = $this->requirePublicUrl($media);
        } else {
            $params['media_type'] = 'TEXT';
        }

        if ($replyTo !== null) {
            $params['reply_to_id'] = $replyTo;
        }

        return $this->createContainer('threads', $threadsUserId, $token, $params);
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
            fn (): Response => $this->http()->get($this->graphUrl('me'), ['fields' => 'id,username', 'access_token' => $token]),
            'fetching the account profile',
        );

        $id = $me['id'] ?? null;
        $username = $me['username'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($username) || $username === '') {
            throw new ProviderAuthException("{$this->name()} did not return an account id/username.");
        }

        return new AuthResultDto(
            internalId: $id,
            name: '@'.$username,
            token: $token,
            expiresAt: is_int($expiresIn) || is_float($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
        );
    }

    /**
     * Threads' long-lived exchange is its own endpoint
     * (LONG_LIVED_TOKEN_URL, not under graphUrl()) and its own grant
     * (`th_exchange_token`, authenticated with `client_secret` alone —
     * no `client_id`). Reused for refresh() the same way
     * FacebookPageProvider/InstagramProvider reuse theirs: called again
     * later with the current stored token instead of a fresh
     * short-lived one, extending it another ~60 days.
     *
     * @return array<string, mixed>
     */
    private function exchangeForLongLivedToken(string $token): array
    {
        return $this->call(
            fn (): Response => $this->http()->get(self::LONG_LIVED_TOKEN_URL, [
                'grant_type' => 'th_exchange_token',
                'client_secret' => $this->requireConfig('client_secret'),
                'access_token' => $token,
            ]),
            'exchanging the long-lived token',
        );
    }

    protected function configPrefix(): string
    {
        return 'services.meta_threads';
    }
}
