<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsMedia;
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
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * AT Protocol (Bluesky) adapter. Not OAuth: like Telegram, a Bluesky
 * account is connected by the user supplying a handle and an app
 * password (never their main account password -- Bluesky's own
 * documented mechanism for third-party apps), verified via
 * `com.atproto.server.createSession` -- see ManualTokenAuth.
 *
 * Fixed entryway host: unlike Mastodon's user-chosen instance, every
 * call here targets `bsky.social`, the public AT Protocol entryway,
 * which proxies to whichever PDS actually hosts a given account. This
 * host is a constant in this class, never taken from request input, so
 * (unlike MastodonProvider's instance host) there is no SafeRemoteHost
 * check to make: SafeRemoteHost exists to gate a server-side request
 * target an admin controls, and nothing here is admin-controlled. A
 * fixed multi-tenant entryway is a real simplification for an MVP (a
 * self-hosted PDS user would need their own instance host, symmetric to
 * Mastodon) -- documented here rather than silently assumed.
 *
 * Session lifecycle (SupportsRefresh): createSession returns a
 * short-lived accessJwt and a longer-lived refreshJwt. refresh()
 * exchanges the latter via `com.atproto.server.refreshSession`.
 *
 * Two-phase media (SupportsMedia): `com.atproto.repo.uploadBlob`
 * returns a `blob` reference (a small JSON object: $type/ref/mimeType/
 * size), not a simple id -- MediaDto::$remoteId is a single string, so
 * this provider stores the blob reference JSON-encoded in $remoteId and
 * decodes it back when building a post's `embed`.
 *
 * Thread anchor / strong-ref reconstruction (see PostPayloadDto's class
 * docblock for the documented contract this implements): an AT Protocol
 * reply's `record.reply` needs BOTH a `root` and a `parent` strong ref
 * (uri + cid each), but $replyToReleaseId only carries a bare uri (the
 * parent's), because PostResultDto has no cid field. On a resumed
 * chain's first segment, resolveAnchor() calls
 * `com.atproto.repo.getRecord` on that uri to recover its cid, and reads
 * its own `value.reply.root` if it has one (i.e. the anchor was itself a
 * reply) -- otherwise the anchor IS the thread root. This was verified
 * against the documented mechanism in PostPayloadDto's docblock and
 * against AT Protocol's public lexicon (getRecord's response includes
 * `cid` alongside `value`; a reply post's `value.reply.root` is exactly
 * the strong ref of the thread's root); it has not been verified against
 * a live PDS (no credentials available to this task -- see the report).
 * Within a single post() call needing no resumption, root/parent for
 * segments after the first are built from the createRecord responses
 * already in hand, without any getRecord round-trip.
 */
class BlueskyProvider implements DeclaresAuthFields, ManualTokenAuth, SocialProviderInterface, SupportsMedia, SupportsRefresh
{
    use BoundsHttpTimeouts, MapsHttpErrors, ReadsMediaFiles;

    private const string API_BASE = 'https://bsky.social/xrpc';

    private const string COLLECTION = 'app.bsky.feed.post';

    public function identifier(): string
    {
        return 'bluesky';
    }

    public function name(): string
    {
        return 'Bluesky';
    }

    /**
     * A handle plus an app password (never the account password) — this
     * provider has no OAuth flow at all (ManualTokenAuth), so these go
     * straight to the callback. See DeclaresAuthFields.
     *
     * @return list<array{key: string, type: 'text'|'password'}>
     */
    public function authFields(): array
    {
        return [
            ['key' => 'handle', 'type' => 'text'],
            ['key' => 'app_password', 'type' => 'password'],
        ];
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 300,
            maxImages: 4,
            maxVideos: 0,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            maxFileSizeBytes: 1_000_000,
            allowsMixedMedia: false,
            maxThreadLength: null,
            // Empty: an AT Protocol post carries plain text plus
            // facets (byte-range annotations), and this provider does
            // not build facets. Nothing here can render bold, so
            // nothing is offered.
            supportedMarks: [],
            requiresMedia: false,
            requiresAltText: false,
        );
    }

    /**
     * No redirect flow exists for a manual-token provider -- mirrors
     * TelegramProvider::generateAuthUrl(); see ManualTokenAuth's
     * docblock. $params is accepted but unused: the handle and app
     * password are supplied directly to authenticate().
     *
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        return new AuthUrlDto(
            url: 'https://bsky.app/settings/app-passwords',
            extra: ['state' => $state],
        );
    }

    /**
     * Verifies a handle + app password pair by calling createSession.
     * Every non-transient rejection here means the same thing -- these
     * credentials do not work -- so (like TelegramProvider::authenticate())
     * it collapses to ProviderAuthException rather than being split by
     * status code the way post()'s errorFor() is.
     *
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        $handle = $params['handle'] ?? null;
        $appPassword = $params['app_password'] ?? null;

        if (! is_string($handle) || $handle === '') {
            throw new InvalidArgumentException('Bluesky requires a non-empty handle.');
        }

        if (! is_string($appPassword) || $appPassword === '') {
            throw new InvalidArgumentException('Bluesky requires a non-empty app_password.');
        }

        $body = $this->call(
            fn (): Response => $this->http()->post(self::API_BASE.'/com.atproto.server.createSession', [
                'identifier' => $handle,
                'password' => $appPassword,
            ]),
            'creating a session',
            authContext: true,
        );

        return new AuthResultDto(
            internalId: (string) $body['did'],
            name: (string) $body['handle'],
            token: (string) $body['accessJwt'],
            refreshToken: (string) $body['refreshJwt'],
            settings: ['handle' => (string) $body['handle']],
        );
    }

    /**
     * Exchanges the stored refresh token (NOT the access token --
     * refreshSession authenticates via the refresh JWT itself, per AT
     * Protocol's session model) for a fresh access/refresh pair.
     */
    public function refresh(Integration $integration): AuthResultDto
    {
        $refreshToken = (string) $integration->refresh_token;

        $body = $this->call(
            fn (): Response => $this->http()->withToken($refreshToken)
                ->post(self::API_BASE.'/com.atproto.server.refreshSession'),
            'refreshing the session',
            authContext: true,
        );

        return new AuthResultDto(
            internalId: (string) $body['did'],
            name: (string) $body['handle'],
            token: (string) $body['accessJwt'],
            refreshToken: (string) $body['refreshJwt'],
            settings: ['handle' => (string) $body['handle']],
        );
    }

    public function uploadMedia(Integration $integration, MediaDto $media): MediaDto
    {
        $token = (string) $integration->token;

        $body = $this->call(
            fn (): Response => $this->uploadHttp()->withToken($token)
                ->withBody(Utils::streamFor($this->fileStream($media)), $media->mimeType)
                ->post(self::API_BASE.'/com.atproto.repo.uploadBlob'),
            'uploading a blob',
        );

        $blob = $body['blob'] ?? null;

        if (! is_array($blob)) {
            throw new ProviderPermanentException('Bluesky blob upload did not return a blob reference.');
        }

        return $media->withRemoteId((string) json_encode($blob));
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $token = (string) $integration->token;
        $did = (string) $integration->internal_id;

        $results = [];
        /** @var array{uri: string, cid: string}|null $root */
        $root = null;
        /** @var array{uri: string, cid: string}|null $parent */
        $parent = null;

        foreach ($chain as $index => $payload) {
            if ($index === 0 && $payload->replyToReleaseId !== null) {
                [$root, $parent] = $this->resolveAnchor($token, $did, $payload->replyToReleaseId);
            }

            try {
                [$result, $strongRef] = $this->publishSegment($token, $did, $payload, $root, $parent);
            } catch (ProviderException $e) {
                if ($results !== []) {
                    throw new ProviderPartialFailureException($e->getMessage(), $results, $e);
                }

                throw $e;
            }

            $results[$index] = $result;
            $root ??= $strongRef;
            $parent = $strongRef;
        }

        return $results;
    }

    /**
     * @param  array{uri: string, cid: string}|null  $root
     * @param  array{uri: string, cid: string}|null  $parent
     * @return array{0: PostResultDto, 1: array{uri: string, cid: string}}
     */
    private function publishSegment(string $token, string $did, PostPayloadDto $payload, ?array $root, ?array $parent): array
    {
        $record = [
            '$type' => self::COLLECTION,
            'text' => $payload->content,
            'createdAt' => now()->toISOString(),
        ];

        if ($root !== null && $parent !== null) {
            $record['reply'] = ['root' => $root, 'parent' => $parent];
        }

        $images = [];
        foreach ($payload->media as $media) {
            if ($media->remoteId === null) {
                throw new ProviderPermanentException(
                    'Media must be uploaded via uploadMedia() (SupportsMedia) before publishing to Bluesky.',
                );
            }

            $blob = json_decode($media->remoteId, true);

            if (! is_array($blob)) {
                throw new ProviderPermanentException('Bluesky media carries a malformed blob reference.');
            }

            $images[] = [
                'alt' => $media->altText ?? '',
                'image' => $blob,
            ];
        }

        if ($images !== []) {
            $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
        }

        $body = $this->call(
            fn (): Response => $this->http()->withToken($token)->post(self::API_BASE.'/com.atproto.repo.createRecord', [
                'repo' => $did,
                'collection' => self::COLLECTION,
                'record' => $record,
            ]),
            'creating a record',
        );

        $uri = $body['uri'] ?? null;
        $cid = $body['cid'] ?? null;

        if (! is_string($uri) || $uri === '' || ! is_string($cid) || $cid === '') {
            throw new ProviderPermanentException('Bluesky createRecord response did not include a uri/cid.');
        }

        return [new PostResultDto(releaseId: $uri, releaseUrl: $this->releaseUrlFor($did, $uri)), ['uri' => $uri, 'cid' => $cid]];
    }

    /**
     * Reconstructs the root and parent strong refs for a resumed chain's
     * first segment from nothing but the parent's uri -- see this
     * class's docblock and PostPayloadDto's for the documented contract.
     * Fetches the anchor record (getRecord) for its cid and, if it is
     * itself a reply, its embedded reply.root; a record with no `reply`
     * field IS the thread root.
     *
     * @return array{0: array{uri: string, cid: string}, 1: array{uri: string, cid: string}}
     */
    private function resolveAnchor(string $token, string $did, string $anchorUri): array
    {
        $parts = explode('/', $anchorUri);
        $rkey = (string) end($parts);

        if (! str_starts_with($anchorUri, 'at://') || $rkey === '') {
            throw new ProviderPermanentException("Bluesky reply-to anchor [{$anchorUri}] is not a valid record uri.");
        }

        $body = $this->call(
            fn (): Response => $this->http()->withToken($token)->get(self::API_BASE.'/com.atproto.repo.getRecord', [
                'repo' => $did,
                'collection' => self::COLLECTION,
                'rkey' => $rkey,
            ]),
            'resolving the reply-to anchor',
        );

        $cid = $body['cid'] ?? null;

        if (! is_string($cid) || $cid === '') {
            throw new ProviderPermanentException("Bluesky getRecord response for anchor [{$anchorUri}] did not include a cid.");
        }

        $parent = ['uri' => $anchorUri, 'cid' => $cid];

        /** @var array<string, mixed>|null $existingReply */
        $existingReply = is_array($body['value'] ?? null) ? ($body['value']['reply'] ?? null) : null;
        $existingRoot = is_array($existingReply) ? ($existingReply['root'] ?? null) : null;

        if (is_array($existingRoot) && isset($existingRoot['uri'], $existingRoot['cid'])) {
            /** @var array{uri: string, cid: string} $root */
            $root = ['uri' => (string) $existingRoot['uri'], 'cid' => (string) $existingRoot['cid']];

            return [$root, $parent];
        }

        return [$parent, $parent];
    }

    private function releaseUrlFor(string $did, string $uri): ?string
    {
        $parts = explode('/', $uri);
        $rkey = (string) end($parts);

        if ($rkey === '') {
            return null;
        }

        return "https://bsky.app/profile/{$did}/post/{$rkey}";
    }

    /**
     * Runs one Bluesky API call, translating both an unsuccessful HTTP
     * response and a network-level failure into the App\Social\Exceptions
     * taxonomy, and returns the decoded JSON body.
     *
     * $authContext collapses every non-transient rejection into
     * ProviderAuthException (mirrors TelegramProvider::authErrorFor()):
     * used for createSession/refreshSession, where any rejection means
     * the credential itself is no good, not that "content" was invalid.
     *
     * @return array<string, mixed>
     */
    private function call(Closure $call, string $context, bool $authContext = false): array
    {
        $response = $this->send($call);
        $body = $response->json();

        if (! $response->successful()) {
            throw $authContext
                ? $this->authErrorFor($response, $context)
                : $this->errorFor($response, $context);
        }

        /** @var array<string, mixed> */
        return is_array($body) ? $body : [];
    }

    /**
     * Deliberately does NOT attach the caught ConnectionException as
     * $previous (Task 14 review, Finding 2, applied identically here):
     * a raw transport exception's message can echo back request details
     * a caller supplied (here, potentially the app password in a
     * createSession request body, or the access/refresh JWT via an
     * Authorization header) depending on the underlying HTTP client's
     * error-formatting behaviour. See TelegramProvider::send() for the
     * fuller rationale; the policy is blanket, not judged per provider.
     */
    private function send(Closure $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new ProviderTransientException('Network error while calling the Bluesky API.');
        }

        return $this->refuseRedirect($response, $this->name());
    }

    private function errorFor(Response $response, string $context): ProviderException
    {
        $body = $response->json();
        $message = is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? 'unknown error')
            : 'unknown error';

        $retryAfter = (int) $response->header('Retry-After');

        return $this->exceptionForStatus(
            $response->status(),
            "Bluesky API error while {$context} ({$response->status()}): {$message}",
            $retryAfter,
        );
    }

    private function authErrorFor(Response $response, string $context): ProviderException
    {
        $body = $response->json();
        $message = is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? 'unknown error')
            : 'unknown error';

        $status = $response->status();

        if ($status === 429) {
            return new ProviderRateLimitException("Bluesky rate-limited {$context}: {$message}", (int) $response->header('Retry-After'));
        }

        if ($status >= 500) {
            return new ProviderTransientException("Bluesky API error while {$context}: {$message}");
        }

        return new ProviderAuthException("Bluesky rejected the request while {$context}: {$message}");
    }
}
