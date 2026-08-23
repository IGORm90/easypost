<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsMedia;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderTransientException;
use App\Support\Exceptions\UnsafeRemoteHostException;
use App\Support\SafeRemoteHost;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Mastodon adapter: standard OAuth2 (Doorkeeper, the library every
 * Mastodon server runs) against a user-supplied instance host -- unlike
 * Telegram, connecting a Mastodon channel means the frontend collects
 * which server the account lives on (e.g. "mastodon.social") before any
 * redirect can happen at all.
 *
 * Dynamic client registration: Mastodon has no pre-shared client_id --
 * each instance issues its own via `POST /api/v1/apps`
 * (https://docs.joinmastodon.org/methods/apps/#create), which must
 * happen before an authorize URL can be built and again be available at
 * token-exchange time. generateAuthUrl() registers (or reuses a
 * previously registered) app per instance host, cached via Cache
 * (keyed by host, not by the CSRF `state` -- app registration is
 * instance-wide, not per-connect-attempt) so repeat connects to the same
 * instance don't re-register needlessly. This is the reason
 * SocialProviderInterface::generateAuthUrl() grew its `$params`
 * parameter in Task 14 -- see that interface's docblock for the fuller
 * account of why Task 11's original two-argument signature didn't fit.
 *
 * Two-phase media (SupportsMedia): Mastodon's `POST /api/v2/media`
 * returns an attachment id that a subsequent `POST /api/v1/statuses`
 * references via `media_ids` -- exactly the flow MediaDto::$remoteId
 * exists for. post() therefore requires every MediaDto it's given to
 * already carry a $remoteId (i.e. uploadMedia() was called first, e.g.
 * by Task 25's editor); it does not silently upload on the caller's
 * behalf, since doing so would hide upload failures from composition
 * time (uploadMedia()'s own docblock).
 */
class MastodonProvider implements DeclaresAuthFields, SocialProviderInterface, SupportsMedia
{
    use BoundsHttpTimeouts, MapsHttpErrors, ReadsMediaFiles;

    private const string APP_REGISTRATION_CACHE_PREFIX = 'mastodon_app_registration:';

    private const int APP_REGISTRATION_TTL_SECONDS = 60 * 60 * 24 * 30;

    private const string SCOPES = 'read:accounts write:statuses write:media';

    public function identifier(): string
    {
        return 'mastodon';
    }

    public function name(): string
    {
        return 'Mastodon';
    }

    /**
     * The instance host is needed before the redirect can even be built:
     * `generateAuthUrl()` has no url to send the user to without it, and
     * the app registration it performs is per-instance. Declared here so
     * the connect form can ask for it without the frontend knowing which
     * provider needs what — see DeclaresAuthFields.
     *
     * @return list<array{key: string, type: 'text'|'password'}>
     */
    public function authFields(): array
    {
        return [['key' => 'instance', 'type' => 'text']];
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 500,
            maxImages: 4,
            maxVideos: 1,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'video/mp4'],
            maxFileSizeBytes: 40 * 1024 * 1024,
            // A status carries either up to 4 images OR exactly 1
            // video/gif, never both -- Mastodon's own upload limit
            // (https://docs.joinmastodon.org/user/posting/#media).
            allowsMixedMedia: false,
            maxThreadLength: null,
            // Empty, and deliberately so: the HTML a Mastodon status
            // is *served* as is produced by the server from plain
            // text -- POST /api/v1/statuses takes no markup on input
            // (no content_type on vanilla Mastodon). Offering bold
            // here would publish literal asterisks.
            supportedMarks: [],
            requiresMedia: false,
            requiresAltText: false,
        );
    }

    /**
     * Registers (or reuses) an OAuth app on the caller-supplied instance
     * and returns the authorize URL for it. $params['instance'] is
     * required -- there is no default host, per the brief's "user-supplied
     * instance host" requirement -- and missing it is a caller/config bug
     * (the frontend must always collect it before calling this), not a
     * live-network outcome, so it throws InvalidArgumentException rather
     * than something from the ProviderException taxonomy.
     *
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        [$host, $pin] = $this->normalizeHost($params['instance'] ?? null);
        $app = $this->registerOrReuseApp($host, $pin, $redirectUri);

        $query = http_build_query([
            'client_id' => $app['client_id'],
            'scope' => self::SCOPES,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);

        return new AuthUrlDto(
            url: "https://{$host}/oauth/authorize?{$query}",
            extra: ['instance' => $host],
        );
    }

    /**
     * Exchanges an authorization code for an access token and fetches
     * the connected account's identity. $params must carry `instance`
     * (the same host generateAuthUrl() was called with -- the frontend is
     * responsible for round-tripping it, since callback() forwards
     * whatever it receives untouched) and `code` (Mastodon's redirect
     * query parameter).
     *
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        [$host, $pin] = $this->normalizeHost($params['instance'] ?? null);
        $code = $params['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new InvalidArgumentException('Mastodon requires an authorization code.');
        }

        $app = self::cachedApp($host);

        if ($app === null) {
            throw new ProviderPermanentException(
                "No Mastodon app registration found for instance [{$host}]; the connect flow must be restarted.",
            );
        }

        $tokenResponse = $this->send(fn (): Response => $this->http()->withOptions($pin)->asForm()->post("https://{$host}/oauth/token", [
            'grant_type' => 'authorization_code',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'scope' => self::SCOPES,
        ]));

        if (! $tokenResponse->successful()) {
            throw $this->errorFor($tokenResponse, 'exchanging the authorization code');
        }

        $accessToken = $tokenResponse->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new ProviderPermanentException('Mastodon token exchange did not return an access_token.');
        }

        $accountResponse = $this->send(fn (): Response => $this->http()->withOptions($pin)->withToken($accessToken)
            ->get("https://{$host}/api/v1/accounts/verify_credentials"));

        if (! $accountResponse->successful()) {
            throw $this->errorFor($accountResponse, 'verifying the connected account');
        }

        /** @var array<string, mixed> $account */
        $account = (array) $accountResponse->json();

        return new AuthResultDto(
            internalId: (string) $account['id'],
            name: (string) ($account['display_name'] ?: $account['username']),
            token: $accessToken,
            picture: is_string($account['avatar_static'] ?? null) ? $account['avatar_static'] : null,
            settings: ['instance' => $host],
        );
    }

    public function uploadMedia(Integration $integration, MediaDto $media): MediaDto
    {
        [$host, $pin] = $this->hostFor($integration);
        $token = (string) $integration->token;

        $body = $this->call(
            fn (): Response => $this->uploadHttp()->withOptions($pin)->withToken($token)
                ->attach('file', $this->fileStream($media), $media->filename(), $this->fileHeaders($media))
                ->post("https://{$host}/api/v2/media", array_filter([
                    'description' => $media->altText,
                ], static fn (mixed $value): bool => $value !== null)),
            'uploading media',
        );

        $id = $body['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            throw new ProviderPermanentException('Mastodon media upload did not return an id.');
        }

        return $media->withRemoteId((string) $id);
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        [$host, $pin] = $this->hostFor($integration);
        $token = (string) $integration->token;

        $results = [];
        $previousStatusId = null;

        foreach ($chain as $index => $payload) {
            $replyTo = $index === 0 ? $payload->replyToReleaseId : $previousStatusId;

            try {
                $result = $this->publishSegment($host, $pin, $token, $payload, $replyTo);
            } catch (ProviderException $e) {
                if ($results !== []) {
                    throw new ProviderPartialFailureException($e->getMessage(), $results, $e);
                }

                throw $e;
            }

            $results[$index] = $result;
            $previousStatusId = $result->releaseId;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $pin  Connection-pinning options from hostFor() — see SafeRemoteHost::pinOptions().
     */
    private function publishSegment(string $host, array $pin, string $token, PostPayloadDto $payload, ?string $replyTo): PostResultDto
    {
        $mediaIds = [];

        foreach ($payload->media as $media) {
            if ($media->remoteId === null) {
                throw new ProviderPermanentException(
                    'Media must be uploaded via uploadMedia() (SupportsMedia) before publishing to Mastodon.',
                );
            }

            $mediaIds[] = $media->remoteId;
        }

        $body = $this->call(
            fn (): Response => $this->http()->withOptions($pin)->withToken($token)->asForm()->post("https://{$host}/api/v1/statuses", array_filter([
                'status' => $payload->content,
                'media_ids' => $mediaIds !== [] ? $mediaIds : null,
                'in_reply_to_id' => $replyTo,
                'visibility' => 'public',
            ], static fn (mixed $value): bool => $value !== null)),
            'publishing a status',
        );

        $id = $body['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            throw new ProviderPermanentException('Mastodon status response did not include an id.');
        }

        return new PostResultDto(
            releaseId: (string) $id,
            releaseUrl: is_string($body['url'] ?? null) ? $body['url'] : null,
        );
    }

    /**
     * Registers a new OAuth app on $host, or returns the previously
     * registered one from cache. Cached per-host (not per-connect-attempt)
     * since Mastodon app registration is a property of the instance, not
     * of any one user's connect flow -- reusing it avoids leaving behind
     * a fresh "application" entry in the instance's authorized-apps list
     * every time someone in this app connects the same server.
     *
     * @param  array<string, mixed>  $pin  Connection-pinning options for $host — see SafeRemoteHost::pinOptions().
     * @return array{client_id: string, client_secret: string}
     */
    private function registerOrReuseApp(string $host, array $pin, string $redirectUri): array
    {
        $cached = self::cachedApp($host);

        if ($cached !== null) {
            return $cached;
        }

        $body = $this->call(
            fn (): Response => $this->http()->withOptions($pin)->asForm()->post("https://{$host}/api/v1/apps", [
                'client_name' => (string) config('app.name', 'EasyPost Scheduler'),
                'redirect_uris' => $redirectUri,
                'scopes' => self::SCOPES,
                'website' => (string) config('app.url'),
            ]),
            'registering the OAuth application',
        );

        $clientId = $body['client_id'] ?? null;
        $clientSecret = $body['client_secret'] ?? null;

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new ProviderPermanentException("Mastodon instance [{$host}] did not return app credentials.");
        }

        self::cacheApp($host, ['client_id' => $clientId, 'client_secret' => $clientSecret]);

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    /**
     * Reads back what cacheApp() wrote. Returns null for a missing entry
     * AND for one this application can no longer decrypt (an APP_KEY
     * rotation, a hand-edited Redis value): both mean the same thing to
     * every caller — there is no usable registration for this host, so
     * register again / restart the connect flow — and neither is worth
     * surfacing a decryption error for.
     *
     * @return array{client_id: string, client_secret: string}|null
     */
    private static function cachedApp(string $host): ?array
    {
        $cached = Cache::get(self::appCacheKey($host));

        if (! is_string($cached)) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cached), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['client_id'] ?? null) || ! is_string($decoded['client_secret'] ?? null)) {
            return null;
        }

        return ['client_id' => $decoded['client_id'], 'client_secret' => $decoded['client_secret']];
    }

    /**
     * Final-review batch 2, Important 7: `client_secret` is a real OAuth
     * client credential for that instance, and it used to sit in Redis in
     * plaintext for 30 days — the only credential in this application
     * stored anywhere without the `encrypted` treatment every
     * `integrations` column gets. Redis is not encrypted at rest here, so
     * anything with read access to it (a backup, an operator, an RCE in
     * an unrelated service sharing the instance) had it verbatim.
     * Encrypted with the application key, exactly like the token columns.
     *
     * @param  array{client_id: string, client_secret: string}  $app
     */
    private static function cacheApp(string $host, array $app): void
    {
        Cache::put(
            self::appCacheKey($host),
            Crypt::encryptString((string) json_encode($app)),
            self::APP_REGISTRATION_TTL_SECONDS,
        );
    }

    /**
     * Resolves the stored instance host for an already-connected channel
     * AND re-validates it against SafeRemoteHost before returning --
     * deliberately, not just at connect time (Task 14 review, Finding
     * 1): DNS answers change, so a host that resolved to a public
     * address when the channel was connected can resolve somewhere
     * unsafe (e.g. 169.254.169.254) by the time a later scheduled
     * publish runs. Every caller of post()/uploadMedia() goes through
     * this single choke point, so re-validation happens on every use,
     * not just once. Mapped to ProviderPermanentException (not
     * transient): retrying won't change what the channel's stored host
     * currently resolves to, and this needs a human to look at the
     * channel, not an automatic retry.
     *
     * Returns the host AND the connection-pinning options for it
     * (final-review batch 2, Critical 1/Important 6): every request built
     * from this host must carry them, so the address this method
     * validated is the address cURL actually connects to — closing both
     * the octal/hex parser-differential bypass and the rebinding window
     * between this check and the request that follows it. Callers
     * destructure: `[$host, $pin] = $this->hostFor($integration)`.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function hostFor(Integration $integration): array
    {
        $host = $integration->settings['instance'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new ProviderPermanentException('This Mastodon channel has no instance host recorded in its settings.');
        }

        try {
            // The normalised host, not the stored one: it is what the pin
            // is keyed on and what the URL must be built from (re-review
            // finding 5).
            [$host, $ips] = SafeRemoteHost::assertSafeHostPinned($host);

            return [$host, SafeRemoteHost::pinOptions($host, $ips)];
        } catch (UnsafeRemoteHostException $e) {
            throw new ProviderPermanentException(
                "This Mastodon channel's instance host is no longer safe to connect to: {$e->getMessage()}",
            );
        }
    }

    /**
     * Validates connect-time input: format first (a cheap rejection for
     * obvious garbage before any network call), then SafeRemoteHost
     * (Task 14 review, Finding 1) so an org admin cannot aim the
     * connect flow -- and therefore every future scheduled publish
     * through this channel -- at a loopback, private, link-local (e.g.
     * the 169.254.169.254 cloud-metadata address) or otherwise reserved
     * address. Both failure modes are the caller's fault (bad/unsafe
     * input), not a live-network outcome, so both throw
     * InvalidArgumentException rather than a ProviderException.
     *
     * Returns `[host, pinOptions]` for the same reason hostFor() does —
     * see that method's docblock. Note that the `^[a-z0-9.-]+$` format
     * check below happily admits `0177.0.0.1`; what refuses it is
     * SafeRemoteHost's own ambiguous-literal check, which is where that
     * rule belongs (every caller of that class gets it, not just this
     * one).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function normalizeHost(mixed $instance): array
    {
        if (! is_string($instance) || $instance === '') {
            throw new InvalidArgumentException('Mastodon requires an instance host (params[instance]).');
        }

        $host = preg_replace('#^https?://#i', '', trim($instance));
        $host = rtrim((string) $host, '/');

        if ($host === '' || str_contains($host, '/') || ! preg_match('/^[a-z0-9.-]+$/iD', $host)) {
            throw new InvalidArgumentException("Mastodon instance host [{$instance}] is not a valid hostname.");
        }

        try {
            return SafeRemoteHost::assertSafeUrlPinned("https://{$host}");
        } catch (UnsafeRemoteHostException $e) {
            throw new InvalidArgumentException("Mastodon instance host [{$host}] is not safe to connect to: {$e->getMessage()}");
        }
    }

    /**
     * Runs one Mastodon API call, translating both an unsuccessful HTTP
     * response and a network-level failure into the App\Social\Exceptions
     * taxonomy, and returns the decoded JSON body.
     *
     * @return array<string, mixed>
     */
    private function call(Closure $call, string $context): array
    {
        $response = $this->send($call);

        if (! $response->successful()) {
            throw $this->errorFor($response, $context);
        }

        /** @var array<string, mixed> */
        return (array) $response->json();
    }

    /**
     * Deliberately does NOT attach the caught ConnectionException as
     * $previous (Task 14 review, Finding 2 -- applied here too, even
     * though Mastodon's own credentials travel via the Authorization
     * header rather than the URL: the policy is "never chain a raw
     * transport exception," not "only when this specific provider is
     * known to embed a secret in the URL today," since that's exactly
     * the kind of assumption a future code change could quietly
     * invalidate). See TelegramProvider::send() for the fuller
     * rationale.
     */
    private function send(Closure $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new ProviderTransientException('Network error while calling the Mastodon API.');
        }

        return $this->refuseRedirect($response, $this->name());
    }

    /**
     * Mastodon reports errors via plain HTTP status codes and (for 429)
     * a standard `Retry-After` header -- unlike Telegram, there is no
     * provider-specific body envelope to parse for the mapping itself,
     * so this leans almost entirely on the shared table in
     * MapsHttpErrors. What's still provider-specific: reading the
     * `Retry-After` header and the `error`/`error_description` body
     * fields Mastodon actually uses for its message text.
     */
    private function errorFor(Response $response, string $context): ProviderException
    {
        $body = $response->json();
        $message = is_array($body)
            ? (string) ($body['error_description'] ?? $body['error'] ?? 'unknown error')
            : 'unknown error';

        $retryAfter = (int) $response->header('Retry-After');

        return $this->exceptionForStatus(
            $response->status(),
            "Mastodon API error while {$context} ({$response->status()}): {$message}",
            $retryAfter,
        );
    }

    private static function appCacheKey(string $host): string
    {
        return self::APP_REGISTRATION_CACHE_PREFIX.$host;
    }
}
