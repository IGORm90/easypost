<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\DeclaresPostSettings;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsMedia;
use App\Social\Contracts\SupportsOptions;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\PostResultDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Dto\TextMark;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Exceptions\UnknownProviderOptionException;
use App\Support\Exceptions\UnsafeRemoteHostException;
use App\Support\SafeRemoteHost;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reddit adapter. OAuth 2.0, authorization-code grant against ONE
 * app-level client registered by the operator (config('services.reddit'))
 * -- deliberately NOT ManualTokenAuth and NOT DeclaresAuthFields, which
 * together are what makes `GET /api/providers` report
 * `auth_type: 'oauth'` with an empty `auth_fields` list, which in turn is
 * what makes the SPA's Channels/Index.vue skip the connect modal
 * entirely and go straight to Reddit's own authorize page. Every
 * connected user authorizes their own Reddit account against this one
 * app and gets their own token; posts publish as them, and the shared
 * client id is a shared OAuth rate limit and a shared blast radius if
 * Reddit ever suspends the app -- an accepted, documented trade for the
 * one-click connect this network was asked for.
 *
 * `duration=permanent` on the authorize URL is not optional: without it
 * Reddit issues no refresh token at all, and every connected channel
 * would go dead the first time its one-hour access token expired, with
 * nothing in this app noticing until a publish failed.
 *
 * A subreddit is chosen per POST, not per channel (DeclaresPostSettings,
 * not a channel setting) -- one connected Reddit account can post to any
 * subreddit it belongs to, so pinning a subreddit to the channel the way
 * Telegram pins a chat id would be strictly worse. There is accordingly
 * nothing for DeclaresUpdatableSettings to expose: nothing about a
 * connected Reddit channel is a "setting" a user changes after the fact.
 *
 * User-Agent: Reddit blocks harder on a missing/generic User-Agent than
 * on request rate (config('services.reddit.user_agent') carries the
 * operator's own contact-bearing string) -- send() attaches it to every
 * call, and a blank one fails the call before any request goes out
 * rather than being sent and silently rejected by Reddit.
 *
 * Markup: this app's canonical post body (**bold**, *italic*, ~~strike~~,
 * `code`, [text](url) -- see TextMark) already IS the Markdown dialect
 * Reddit renders, so capabilities() declares the full mark set and the
 * body is sent byte-for-byte -- unlike Telegram, this provider does NOT
 * use TranslatesMarkup, because there is nothing to translate.
 *
 * Threads: a chain publishes as one submission (chain[0]) followed by a
 * comment per subsequent segment, each replying to the previous --
 * SocialProviderInterface::post()'s and PostPayloadDto's contract for
 * $replyToReleaseId is honoured the same way: a resumed chain's first
 * segment becomes a COMMENT on the named fullname, never a second,
 * detached submission.
 */
class RedditProvider implements DeclaresPostSettings, SocialProviderInterface, SupportsMedia, SupportsOptions, SupportsRefresh
{
    use BoundsHttpTimeouts, MapsHttpErrors, ReadsMediaFiles;

    private const string AUTHORIZE_URL = 'https://www.reddit.com/api/v1/authorize';

    private const string TOKEN_URL = 'https://www.reddit.com/api/v1/access_token';

    private const string API_BASE = 'https://oauth.reddit.com';

    private const string SCOPES = 'identity submit flair read mysubreddits';

    private const string SUBREDDIT_PATTERN = '/^[A-Za-z0-9_]{2,50}$/';

    public function identifier(): string
    {
        return 'reddit';
    }

    public function name(): string
    {
        return 'Reddit';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 40_000,
            maxImages: 20,
            maxVideos: 0,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/gif'],
            maxFileSizeBytes: 20_000_000,
            allowsMixedMedia: false,
            maxThreadLength: null,
            supportedMarks: [TextMark::BOLD, TextMark::ITALIC, TextMark::STRIKE, TextMark::CODE, TextMark::LINK],
            requiresMedia: false,
            requiresAltText: false,
        );
    }

    /**
     * @param  array<string, mixed>  $params  Unused: nothing must be chosen before the redirect for a fixed, non-federated network like Reddit.
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        $clientId = $this->requireConfig('client_id');

        $query = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $redirectUri,
            // Without this, Reddit issues an access token only -- no
            // refresh token -- and the channel goes dead after an hour.
            'duration' => 'permanent',
            'scope' => self::SCOPES,
        ]);

        return new AuthUrlDto(url: self::AUTHORIZE_URL.'?'.$query);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        $code = $params['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new ProviderAuthException('Reddit did not return an authorization code.');
        }

        $body = $this->call(
            fn (): Response => $this->tokenRequest()->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]),
            'exchanging the authorization code',
            authContext: true,
        );

        return $this->authResultFromToken($body);
    }

    public function refresh(Integration $integration): AuthResultDto
    {
        $refreshToken = (string) $integration->refresh_token;

        if ($refreshToken === '') {
            throw new ProviderAuthException('This Reddit channel has no refresh token; it must be reconnected.');
        }

        $body = $this->call(
            fn (): Response => $this->tokenRequest()->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]),
            'refreshing the token',
            authContext: true,
        );

        // Reddit does not always re-issue a refresh token on refresh;
        // when it doesn't, the existing one remains valid and must be
        // kept, or the next refresh has nothing to use.
        if (! isset($body['refresh_token'])) {
            $body['refresh_token'] = $refreshToken;
        }

        return $this->authResultFromToken($body);
    }

    /**
     * @return list<array{key: string, type: 'text'|'textarea'|'select', required: bool, rules: list<string>, option?: string, dependsOn?: string}>
     */
    public function postSettings(): array
    {
        return [
            ['key' => 'subreddit', 'type' => 'select', 'required' => true, 'rules' => ['string', 'max:50', 'regex:'.self::SUBREDDIT_PATTERN], 'option' => 'subreddits'],
            ['key' => 'title', 'type' => 'text', 'required' => true, 'rules' => ['string', 'min:1', 'max:300']],
            ['key' => 'flair_id', 'type' => 'select', 'required' => false, 'rules' => ['nullable', 'string', 'max:64'], 'option' => 'flairs', 'dependsOn' => 'subreddit'],
            ['key' => 'url', 'type' => 'text', 'required' => false, 'rules' => ['nullable', 'url:http,https']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function settingOptions(): array
    {
        // Nothing about a connected Reddit channel is a per-channel
        // setting a user later changes -- the subreddit is chosen per
        // post (see postSettings()), so there is nothing to map here.
        return [];
    }

    /**
     * @param  array<string, string>  $context
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option, array $context = []): array
    {
        return match ($option) {
            'subreddits' => $this->subredditOptions($integration),
            'flairs' => $this->flairOptions($integration, $context),
            default => throw UnknownProviderOptionException::forOption($this->identifier(), $option),
        };
    }

    public function uploadMedia(Integration $integration, MediaDto $media): MediaDto
    {
        $token = (string) $integration->token;

        $body = $this->call(
            fn (): Response => $this->authed($token)->asForm()->post(self::API_BASE.'/api/media/asset.json', [
                'filepath' => $media->filename(),
                'mimetype' => $media->mimeType,
            ]),
            'requesting a media upload lease',
        );

        $args = $body['args'] ?? null;
        $asset = $body['asset'] ?? null;

        if (! is_array($args) || ! is_string($args['action'] ?? null) || ! is_array($args['fields'] ?? null)
            || ! is_array($asset) || ! is_string($asset['asset_id'] ?? null)) {
            throw new ProviderPermanentException('Reddit media upload lease did not include an action, fields, and asset id.');
        }

        $action = $args['action'];
        $action = str_starts_with($action, 'http') ? $action : 'https:'.$action;

        // The upload target is a host REDDIT'S RESPONSE named, not one
        // this app configured -- unlike the fixed api/authorize hosts
        // above, it must be validated (and pinned) the same way any
        // other caller-influenced remote host is, or a forged/compromised
        // response could redirect this server-side upload anywhere.
        try {
            [, $pin] = SafeRemoteHost::assertSafeUrlPinned($action, ['https']);
        } catch (UnsafeRemoteHostException $e) {
            throw new ProviderPermanentException('Reddit media upload target is not a safe host: '.$e->getMessage());
        }

        $fields = [];

        foreach ($args['fields'] as $field) {
            if (is_array($field) && isset($field['name'], $field['value'])) {
                $fields[] = ['name' => (string) $field['name'], 'contents' => (string) $field['value']];
            }
        }

        $fields[] = [
            'name' => 'file',
            'contents' => $this->fileStream($media),
            'filename' => $media->filename(),
            'headers' => $this->fileHeaders($media),
        ];

        $uploadResponse = $this->send(fn (): Response => $this->uploadHttp()
            ->withOptions($pin)
            ->asMultipart()
            ->post($action, $fields));

        if (! $uploadResponse->successful()) {
            throw $this->errorFor($uploadResponse, 'uploading media');
        }

        $assetId = (string) $asset['asset_id'];
        $publicUrl = 'https://reddit.com/media?url='.urlencode($this->websocketUrlFallback($body, $assetId));

        return $media->withRemoteId((string) json_encode(['asset_id' => $assetId, 'url' => $publicUrl]));
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $token = (string) $integration->token;

        $results = [];
        $previousFullname = null;

        foreach ($chain as $index => $payload) {
            $replyTo = $index === 0 ? $payload->replyToReleaseId : $previousFullname;

            try {
                $result = $replyTo === null
                    ? $this->submit($token, $payload)
                    : $this->comment($token, $replyTo, $payload->content);
            } catch (ProviderException $e) {
                if ($results !== []) {
                    throw new ProviderPartialFailureException($e->getMessage(), $results, $e);
                }

                throw $e;
            }

            $results[$index] = $result;
            $previousFullname = $result->releaseId;
        }

        return $results;
    }

    private function submit(string $token, PostPayloadDto $payload): PostResultDto
    {
        $form = [
            'api_type' => 'json',
            'sr' => (string) ($payload->settings['subreddit'] ?? ''),
            'title' => (string) ($payload->settings['title'] ?? ''),
            'resubmit' => 'false',
            'sendreplies' => 'false',
        ];

        if (($flairId = $payload->settings['flair_id'] ?? null) !== null && $flairId !== '') {
            $form['flair_id'] = (string) $flairId;
        }

        $media = $this->uploadedMedia($payload->media);
        $url = $payload->settings['url'] ?? null;

        if (count($media) > 1) {
            $form['kind'] = 'gallery';
            $form['items'] = json_encode(array_map(
                fn (MediaDto $m): array => ['caption' => $m->altText ?? '', ...$this->assetRef($m)],
                $media,
            ));
        } elseif (count($media) === 1) {
            $form['kind'] = 'image';
            $form['url'] = $this->assetRef($media[0])['url'];
        } elseif (is_string($url) && $url !== '') {
            $form['kind'] = 'link';
            $form['url'] = $url;
        } else {
            $form['kind'] = 'self';
            $form['text'] = $payload->content;
        }

        $body = $this->call(
            fn (): Response => $this->authed($token)->asForm()->post(self::API_BASE.'/api/submit', $form),
            'submitting a post',
        );

        return $this->resultFromEnvelope($body, 'submit');
    }

    private function comment(string $token, string $thingId, string $text): PostResultDto
    {
        $body = $this->call(
            fn (): Response => $this->authed($token)->asForm()->post(self::API_BASE.'/api/comment', [
                'api_type' => 'json',
                'thing_id' => $thingId,
                'text' => $text,
            ]),
            'posting a comment',
        );

        return $this->resultFromEnvelope($body, 'comment');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function resultFromEnvelope(array $body, string $kind): PostResultDto
    {
        $json = $body['json'] ?? null;

        if (! is_array($json)) {
            throw new ProviderPermanentException("Reddit {$kind} response did not include a json envelope.");
        }

        $errors = $json['errors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            throw $this->exceptionForErrors($errors, $kind);
        }

        $data = $json['data'] ?? null;

        if ($kind === 'submit') {
            $name = is_array($data) ? ($data['name'] ?? null) : null;
            $url = is_array($data) ? ($data['url'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                throw new ProviderPermanentException('Reddit submit response did not include a post name.');
            }

            return new PostResultDto(releaseId: $name, releaseUrl: is_string($url) ? $url : null);
        }

        $things = is_array($data) ? ($data['things'] ?? null) : null;
        $first = is_array($things) ? ($things[0] ?? null) : null;
        $name = is_array($first) ? ($first['data']['name'] ?? null) : null;

        if (! is_string($name) || $name === '') {
            throw new ProviderPermanentException('Reddit comment response did not include a comment name.');
        }

        return new PostResultDto(releaseId: $name);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function subredditOptions(Integration $integration): array
    {
        $body = $this->call(
            fn (): Response => $this->authed((string) $integration->token)->get(self::API_BASE.'/subreddits/mine/subscriber', ['limit' => 100]),
            'listing subreddits',
        );

        $children = $body['data']['children'] ?? [];

        if (! is_array($children)) {
            return [];
        }

        $options = [];

        foreach ($children as $child) {
            $data = is_array($child) ? ($child['data'] ?? null) : null;

            if (! is_array($data) || ! is_string($data['display_name'] ?? null)) {
                continue;
            }

            $options[] = [
                'value' => $data['display_name'],
                'label' => is_string($data['display_name_prefixed'] ?? null) ? $data['display_name_prefixed'] : 'r/'.$data['display_name'],
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $context
     * @return array<int, array{value: string, label: string}>
     */
    private function flairOptions(Integration $integration, array $context): array
    {
        $subreddit = $context['subreddit'] ?? null;

        // A dependent list is asked for before the parent field has a
        // value -- SupportsOptions requires an empty list here, not an
        // exception, so the editor doesn't error out on first render.
        if (! is_string($subreddit) || $subreddit === '') {
            return [];
        }

        // User-controlled input headed into a URL path segment: rejected
        // before it reaches the request, same as everywhere else in this
        // app that builds an outbound URL from caller-supplied text.
        if (preg_match(self::SUBREDDIT_PATTERN, $subreddit) !== 1) {
            return [];
        }

        $body = $this->rawCall(
            fn (): Response => $this->authed((string) $integration->token)->get(self::API_BASE."/r/{$subreddit}/api/link_flair_v2"),
            'listing flairs',
        );

        if (! is_array($body)) {
            return [];
        }

        $options = [];

        foreach ($body as $flair) {
            if (is_array($flair) && is_string($flair['id'] ?? null) && is_string($flair['text'] ?? null)) {
                $options[] = ['value' => $flair['id'], 'label' => $flair['text']];
            }
        }

        return $options;
    }

    /**
     * @param  MediaDto[]  $media
     * @return MediaDto[]
     */
    private function uploadedMedia(array $media): array
    {
        foreach ($media as $item) {
            if ($item->remoteId === null) {
                throw new ProviderPermanentException('Media must be uploaded via uploadMedia() (SupportsMedia) before publishing to Reddit.');
            }
        }

        return $media;
    }

    /**
     * @return array{asset_id: string, url: string}
     */
    private function assetRef(MediaDto $media): array
    {
        $decoded = json_decode((string) $media->remoteId, true);

        if (! is_array($decoded) || ! is_string($decoded['asset_id'] ?? null) || ! is_string($decoded['url'] ?? null)) {
            throw new ProviderPermanentException('Reddit media carries a malformed asset reference.');
        }

        /** @var array{asset_id: string, url: string} */
        return $decoded;
    }

    /**
     * Reddit's asset.json response carries the eventual public URL under
     * a websocket-notification field on some API versions and as
     * `asset.asset_id`-derived only on others; when neither is present,
     * fall back to a URL built from the asset id, which is the form
     * Reddit's own gallery/image submit endpoints accept.
     *
     * @param  array<string, mixed>  $body
     */
    private function websocketUrlFallback(array $body, string $assetId): string
    {
        $websocket = $body['asset']['websocket_url'] ?? null;

        return is_string($websocket) ? $websocket : "https://i.redd.it/{$assetId}";
    }

    private function requireConfig(string $key): string
    {
        $value = config("services.reddit.{$key}");

        if (! is_string($value) || $value === '') {
            throw new ProviderPermanentException("Reddit is not configured: missing services.reddit.{$key}.");
        }

        return $value;
    }

    private function tokenRequest(): PendingRequest
    {
        return $this->http()
            ->withBasicAuth($this->requireConfig('client_id'), $this->requireConfig('client_secret'))
            ->withHeaders(['User-Agent' => $this->requireConfig('user_agent')]);
    }

    private function authed(string $token): PendingRequest
    {
        return $this->http()
            ->withToken($token)
            ->withHeaders(['User-Agent' => $this->requireConfig('user_agent')]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function authResultFromToken(array $body): AuthResultDto
    {
        $accessToken = $body['access_token'] ?? null;
        $expiresIn = $body['expires_in'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new ProviderAuthException('Reddit did not return an access token.');
        }

        $me = $this->call(
            fn (): Response => $this->authed($accessToken)->get(self::API_BASE.'/api/v1/me'),
            'fetching the account profile',
            authContext: true,
        );

        $id = $me['id'] ?? null;
        $name = $me['name'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
            throw new ProviderAuthException('Reddit did not return an account id/name.');
        }

        $refreshToken = $body['refresh_token'] ?? null;
        $icon = $me['icon_img'] ?? null;

        return new AuthResultDto(
            internalId: $id,
            name: 'u/'.$name,
            token: $accessToken,
            refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            expiresAt: is_int($expiresIn) || is_float($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
            picture: is_string($icon) && $icon !== '' ? html_entity_decode($icon) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function call(Closure $call, string $context, bool $authContext = false): array
    {
        $response = $this->send($call);

        if (! $response->successful()) {
            throw $authContext ? $this->authErrorFor($response, $context) : $this->errorFor($response, $context);
        }

        $body = $response->json();

        /** @var array<string, mixed> */
        return is_array($body) ? $body : [];
    }

    /**
     * Like call(), but returns whatever JSON shape Reddit sent
     * (link_flair_v2 answers with a bare array, not an object) rather
     * than forcing it into a string-keyed map.
     */
    private function rawCall(Closure $call, string $context): mixed
    {
        $response = $this->send($call);

        if (! $response->successful()) {
            throw $this->errorFor($response, $context);
        }

        return $response->json();
    }

    /**
     * Deliberately does NOT attach the caught ConnectionException as
     * $previous, mirroring every other provider in this directory: its
     * message can echo request details (here, the bearer token or the
     * client secret via HTTP Basic) depending on the underlying HTTP
     * client's error formatting.
     */
    private function send(Closure $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new ProviderTransientException('Network error while calling the Reddit API.');
        }

        return $this->refuseRedirect($response, $this->name());
    }

    private function errorFor(Response $response, string $context): ProviderException
    {
        $retryAfter = (int) $response->header('Retry-After');

        return $this->exceptionForStatus(
            $response->status(),
            "Reddit API error while {$context} ({$response->status()}).",
            $retryAfter,
        );
    }

    private function authErrorFor(Response $response, string $context): ProviderException
    {
        $status = $response->status();

        if ($status === 429) {
            return new ProviderRateLimitException("Reddit rate-limited {$context}.", (int) $response->header('Retry-After'));
        }

        if ($status >= 500) {
            return new ProviderTransientException("Reddit API error while {$context}.");
        }

        return new ProviderAuthException("Reddit rejected the request while {$context}.");
    }

    /**
     * Reddit reports a `RATELIMIT` error INSIDE a 200 envelope, complete
     * with the wait it wants in the free-text explanation
     * ("you are doing that too much. try again in 9 minutes."), rather
     * than an HTTP 429 -- this is the provider-specific detection half
     * that MapsHttpErrors' docblock says stays here; the decision table
     * itself is shared via exceptionForStatus().
     *
     * @param  array<int, mixed>  $errors
     */
    private function exceptionForErrors(array $errors, string $context): ProviderException
    {
        $codes = [];

        foreach ($errors as $error) {
            if (is_array($error) && is_string($error[0] ?? null)) {
                $codes[] = $error[0];
            }
        }

        $message = "Reddit rejected the request while {$context}: ".implode(', ', $codes !== [] ? $codes : ['unknown error']);

        if (in_array('RATELIMIT', $codes, true)) {
            return new ProviderRateLimitException($message, $this->retryAfterFromRatelimitError($errors));
        }

        return new ProviderPermanentException($message);
    }

    /**
     * @param  array<int, mixed>  $errors
     */
    private function retryAfterFromRatelimitError(array $errors): int
    {
        foreach ($errors as $error) {
            if (is_array($error) && ($error[0] ?? null) === 'RATELIMIT' && is_string($error[1] ?? null)) {
                if (preg_match('/(\d+)\s*minute/i', $error[1], $m) === 1) {
                    return ((int) $m[1]) * 60;
                }

                if (preg_match('/(\d+)\s*second/i', $error[1], $m) === 1) {
                    return (int) $m[1];
                }
            }
        }

        return 0;
    }
}
