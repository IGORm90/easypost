<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsMedia;
use App\Social\Contracts\SupportsOptions;
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
use App\Social\Exceptions\UnknownProviderOptionException;
use App\Support\Exceptions\UnsafeRemoteHostException;
use App\Support\SafeRemoteHost;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Slack adapter: standard OAuth2 (`chat:write`, `files:write`,
 * `channels:read`), publishing via `chat.postMessage`.
 *
 * Error envelope: like Telegram, Slack's Web API always answers with
 * HTTP 200 and a JSON envelope carrying `ok: true|false` for ordinary
 * API errors (`invalid_auth`, `channel_not_found`, `msg_too_long`, ...)
 * -- a real (non-200) HTTP status is reserved for true rate-limiting
 * (429) and backend outages (5xx). call()/errorFor() below check both:
 * the HTTP status for the two "network-shape" cases, and the `error`
 * string for everything Slack itself rejected inside a 200.
 *
 * Channel selection (SupportsOptions): unlike Telegram/Mastodon/Bluesky
 * (channel/instance fixed at connect time) or Discord (fixed by which
 * channel the OAuth consent screen picked), a connected Slack workspace
 * can post to any channel the bot was invited to -- so the channel is a
 * per-integration setting (`settings.channel_id`) chosen after connecting,
 * and options() (backed by `conversations.list`) is what powers the
 * generic `GET /api/integrations/{id}/options/channels` endpoint
 * (Task 13) the frontend uses to offer that choice.
 *
 * Threads are FLAT, unlike Telegram/Mastodon/Bluesky's linked-list model
 * (each reply pointing at the previous segment): every Slack reply's
 * `thread_ts` must be the THREAD ROOT's ts, not the previous reply's --
 * see post()'s docblock for how this changes the within-chain
 * bookkeeping, and resumption's handling of $replyToReleaseId.
 *
 * Two-phase media (SupportsMedia), and a genuine contract-shape
 * mismatch: Slack's `files.getUploadURLExternal` +
 * `files.completeUploadExternal` flow (the current, non-deprecated
 * upload API) has no equivalent of Mastodon's "upload now, reference an
 * id in the post body later" -- completeUploadExternal is itself the
 * act of sharing a file to a channel/thread, and Slack does not
 * document a way to attach an already-uploaded-but-unshared file id to
 * an arbitrary chat.postMessage call after the fact. uploadMedia() here
 * only performs the first two steps (get an upload URL, PUT the file
 * bytes) so upload failures still surface at composition time per
 * SupportsMedia's contract; publishSegment() defers
 * files.completeUploadExternal to publish time, calling it with the
 * channel/thread_ts known only then. The practical result: a segment
 * with both text and media becomes text as its own message via
 * chat.postMessage) followed by the file(s) shared as a companion
 * message in the same thread via completeUploadExternal -- two adjacent
 * timeline entries, not one combined message. This is a documented
 * approximation, not a hidden one; see task-15-report.md for the
 * reasoning.
 */
class SlackProvider implements SocialProviderInterface, SupportsMedia, SupportsOptions
{
    use BoundsHttpTimeouts, MapsHttpErrors;

    private const string API_BASE = 'https://slack.com/api';

    private const array AUTH_ERRORS = [
        'invalid_auth', 'not_authed', 'account_inactive', 'token_revoked',
        'token_expired', 'invalid_client_id', 'bad_client_secret',
        'invalid_code', 'access_denied',
    ];

    public function identifier(): string
    {
        return 'slack';
    }

    public function name(): string
    {
        return 'Slack';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 40000,
            maxImages: 10,
            maxVideos: 10,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4'],
            maxFileSizeBytes: 1024 * 1024 * 1024,
            allowsMixedMedia: true,
            maxThreadLength: null,
            requiresAltText: false,
        );
    }

    /**
     * $params is accepted (per SocialProviderInterface) but unused:
     * Slack needs no per-connect-attempt configuration ahead of the
     * redirect -- client_id/scopes are fixed, from config.
     *
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        $query = http_build_query([
            'client_id' => (string) config('services.slack.integration.client_id'),
            'scope' => 'chat:write,files:write,channels:read',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return new AuthUrlDto(
            url: "https://slack.com/oauth/v2/authorize?{$query}",
            extra: [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        $code = $params['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new InvalidArgumentException('Slack requires an authorization code.');
        }

        $body = $this->call(
            fn (): Response => $this->http()->asForm()->post(self::API_BASE.'/oauth.v2.access', [
                'client_id' => (string) config('services.slack.integration.client_id'),
                'client_secret' => (string) config('services.slack.integration.client_secret'),
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]),
            'exchanging the authorization code',
        );

        $team = $body['team'] ?? null;
        $accessToken = $body['access_token'] ?? null;

        if (! is_array($team) || ! isset($team['id']) || ! is_string($accessToken) || $accessToken === '') {
            throw new ProviderPermanentException('Slack token exchange did not return a team/access_token.');
        }

        return new AuthResultDto(
            internalId: (string) $team['id'],
            name: is_string($team['name'] ?? null) && $team['name'] !== '' ? (string) $team['name'] : 'Slack workspace',
            token: $accessToken,
        );
    }

    /**
     * The only recognised $option is 'channels', backed by
     * `conversations.list`. See this class's docblock for why channel
     * selection is a runtime option here rather than fixed at connect
     * time.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(Integration $integration, string $option): array
    {
        if ($option !== 'channels') {
            throw UnknownProviderOptionException::forOption($this->identifier(), $option);
        }

        $token = (string) $integration->token;

        $body = $this->call(
            fn (): Response => $this->http()->withToken($token)->get(self::API_BASE.'/conversations.list', [
                'types' => 'public_channel,private_channel',
            ]),
            'listing channels',
        );

        $channels = $body['channels'] ?? [];

        if (! is_array($channels)) {
            return [];
        }

        return array_values(array_map(
            fn (array $channel): array => ['value' => (string) $channel['id'], 'label' => (string) $channel['name']],
            $channels,
        ));
    }

    /**
     * Performs only the first two steps of Slack's external-upload flow
     * (get an upload URL, PUT the file bytes) -- see this class's
     * docblock for why the third step (files.completeUploadExternal) is
     * deferred to publish time.
     */
    public function uploadMedia(Integration $integration, MediaDto $media): MediaDto
    {
        $token = (string) $integration->token;
        $filename = basename($media->path);
        $length = (int) filesize($media->path);

        $body = $this->call(
            fn (): Response => $this->http()->withToken($token)->asForm()->post(self::API_BASE.'/files.getUploadURLExternal', [
                'filename' => $filename,
                'length' => $length,
            ]),
            'requesting an upload url',
        );

        $uploadUrl = $body['upload_url'] ?? null;
        $fileId = $body['file_id'] ?? null;

        if (! is_string($uploadUrl) || $uploadUrl === '' || ! is_string($fileId) || $fileId === '') {
            throw new ProviderPermanentException('Slack did not return an upload url/file id.');
        }

        // Pinned through SafeRemoteHost plus an explicit *.slack.com
        // host check, mirroring DiscordProvider::validatedWebhookUrl()
        // (Task 15 review, Finding 3): $uploadUrl is single-use and
        // freshly returned by an authenticated call this same method
        // just made, but that is exactly the same category of value
        // Discord's webhook url is (a network target taken verbatim from
        // a provider API response, never typed by a user) -- treating
        // the two inconsistently was the actual bug, not a considered
        // difference. Defends against a malformed/unexpected
        // upload_url in a future API response, not against the current
        // request being tampered with in flight.
        $this->assertUploadUrlIsSlackHosted($uploadUrl);

        $this->call(
            fn (): Response => $this->uploadHttp()->attach('file', (string) file_get_contents($media->path), $filename)
                ->post($uploadUrl),
            'uploading the file',
            expectsEnvelope: false,
        );

        return $media->withRemoteId($fileId);
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $token = (string) $integration->token;
        $channelId = $integration->settings['channel_id'] ?? null;

        if (! is_string($channelId) || $channelId === '') {
            throw new ProviderPermanentException('This Slack channel has no channel_id configured (settings.channel_id).');
        }

        $results = [];
        // Slack threads are flat: every reply's thread_ts must be the
        // THREAD ROOT's ts (see class docblock), not the previous
        // segment's -- so, unlike Telegram/Mastodon/Bluesky, only one
        // anchor is ever tracked across the whole chain, set once and
        // reused for every subsequent segment.
        $threadTs = null;

        foreach ($chain as $index => $payload) {
            if ($index === 0 && $payload->replyToReleaseId !== null) {
                // Slack resolves any ts belonging to a thread (root or
                // reply) to that thread when used as thread_ts, so the
                // anchor -- the last successfully published segment's own
                // ts -- can be used directly without first resolving it
                // back to the thread's root ts.
                $threadTs = $payload->replyToReleaseId;
            }

            // publishText() and the media share below are deliberately
            // TWO separate try/catch points, not one (Task 15 review,
            // Finding 1 -- CRITICAL): chat.postMessage genuinely
            // publishes a live, visible message the instant it succeeds,
            // before files.completeUploadExternal is ever attempted.
            // Treating the two as a single failable unit meant a
            // completeUploadExternal failure (rate limit, expired
            // upload, 5xx) propagated past the point where $results[$index]
            // was ever set -- so the pipeline was told this segment
            // never published (losing track of a message actually live
            // in the customer's Slack channel) and, on retry, posted it
            // a second time. $results[$index] is now recorded
            // immediately after chat.postMessage succeeds, BEFORE the
            // media share is attempted, so a subsequent media failure
            // can never erase a text message that is already live.
            try {
                $result = $this->publishText($token, $channelId, $payload, $threadTs);
            } catch (ProviderException $e) {
                if ($results !== []) {
                    throw new ProviderPartialFailureException($e->getMessage(), $results, $e);
                }

                throw $e;
            }

            $results[$index] = $result;
            $threadTs ??= $result->releaseId;

            if ($payload->media !== []) {
                try {
                    $this->shareUploadedMedia($token, $channelId, $payload->media, $result->releaseId);
                } catch (ProviderException $e) {
                    // $results already carries this segment's text
                    // message (see above) -- always a partial failure
                    // from here, never a plain rethrow, since something
                    // WAS published even if this is chain index 0.
                    throw new ProviderPartialFailureException(
                        "Slack message was published but attaching media failed: {$e->getMessage()}",
                        $results,
                        $e,
                    );
                }
            }
        }

        return $results;
    }

    private function publishText(string $token, string $channelId, PostPayloadDto $payload, ?string $threadTs): PostResultDto
    {
        // Validated before any request goes out (mirrors
        // MastodonProvider::publishSegment()): a media item missing its
        // pre-upload handle is a caller/composition bug, not something
        // worth posting the text half of the segment for first.
        foreach ($payload->media as $media) {
            if ($media->remoteId === null) {
                throw new ProviderPermanentException(
                    'Media must be uploaded via uploadMedia() (SupportsMedia) before publishing to Slack.',
                );
            }
        }

        // Slack's chat.postMessage rejects an empty `text` outright
        // (error: no_text) unless blocks/attachments are given, which
        // this provider does not build -- and Task 25's editor will let
        // users compose a media-only post with no caption at all (Task
        // 15 review, Minor 3). Every publishText() call posts a
        // standalone text message (see this class's docblock: media is
        // always a companion completeUploadExternal share, never
        // embedded in the same call), so a single space is substituted
        // for genuinely empty content -- Slack's own documented
        // workaround for a caption-less attachment-carrying message,
        // not a byte of the caller's content changed when $payload->content
        // is non-empty.
        $text = $payload->content !== '' ? $payload->content : ' ';

        $body = $this->call(
            fn (): Response => $this->http()->withToken($token)->asForm()->post(self::API_BASE.'/chat.postMessage', array_filter([
                'channel' => $channelId,
                'text' => $text,
                'thread_ts' => $threadTs,
            ], static fn (mixed $value): bool => $value !== null)),
            'posting a message',
        );

        $ts = $body['ts'] ?? null;

        if (! is_string($ts) || $ts === '') {
            throw new ProviderPermanentException('Slack chat.postMessage response did not include a ts.');
        }

        return new PostResultDto(releaseId: $ts);
    }

    /**
     * @param  MediaDto[]  $mediaList
     */
    private function shareUploadedMedia(string $token, string $channelId, array $mediaList, string $threadTs): void
    {
        $files = [];

        foreach ($mediaList as $media) {
            if ($media->remoteId === null) {
                throw new ProviderPermanentException(
                    'Media must be uploaded via uploadMedia() (SupportsMedia) before publishing to Slack.',
                );
            }

            $files[] = ['id' => $media->remoteId];
        }

        $this->call(
            fn (): Response => $this->http()->withToken($token)->post(self::API_BASE.'/files.completeUploadExternal', [
                'files' => $files,
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
            ]),
            'sharing uploaded media',
        );
    }

    /**
     * Mirrors DiscordProvider::validatedWebhookUrl() -- see the call
     * site in uploadMedia() for why this exists (Task 15 review, Finding
     * 3). Mapped to ProviderPermanentException: retrying will not change
     * what Slack just returned.
     */
    private function assertUploadUrlIsSlackHosted(string $uploadUrl): void
    {
        try {
            $host = SafeRemoteHost::assertSafeUrl($uploadUrl);
        } catch (UnsafeRemoteHostException) {
            throw new ProviderPermanentException('Slack upload url is not safe to use.');
        }

        if ($host !== 'slack.com' && ! str_ends_with($host, '.slack.com')) {
            throw new ProviderPermanentException("Slack upload url host [{$host}] is not a slack.com host.");
        }
    }

    /**
     * Runs one Slack API call, translating both an unsuccessful HTTP
     * response and a network-level failure into the App\Social\Exceptions
     * taxonomy, and returns the decoded JSON body.
     *
     * $expectsEnvelope is false for the raw PUT to Slack's pre-signed
     * upload url in uploadMedia(): that endpoint is not a Web API method
     * and does not answer with the standard `{"ok": ...}` envelope, so
     * only the HTTP status is meaningful there.
     *
     * @return array<string, mixed>
     */
    private function call(Closure $call, string $context, bool $expectsEnvelope = true): array
    {
        $response = $this->send($call);
        $body = $response->json();

        if (! $expectsEnvelope) {
            if (! $response->successful()) {
                throw $this->errorFor([], $response, $context);
            }

            return is_array($body) ? $body : [];
        }

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw $this->errorFor(is_array($body) ? $body : [], $response, $context);
        }

        return $body;
    }

    /**
     * Deliberately does NOT attach the caught ConnectionException as
     * $previous (Task 14 review, Finding 2 -- applied here too, even
     * though Slack's bot token travels via the Authorization header
     * rather than the URL). See TelegramProvider::send() for the fuller
     * rationale.
     */
    private function send(Closure $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new ProviderTransientException('Network error while calling the Slack API.');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorFor(array $body, Response $response, string $context): ProviderException
    {
        $error = (string) ($body['error'] ?? 'unknown_error');
        $status = $response->status();
        $retryAfter = (int) $response->header('Retry-After');

        if ($status === 429 || $error === 'ratelimited') {
            return new ProviderRateLimitException("Slack API error while {$context}: {$error}", max(0, $retryAfter));
        }

        if (in_array($error, self::AUTH_ERRORS, true) || $status === 401 || $status === 403) {
            return new ProviderAuthException("Slack API error while {$context}: {$error}");
        }

        if ($status >= 500) {
            return new ProviderTransientException("Slack API error while {$context}: {$error}");
        }

        return new ProviderPermanentException("Slack API error while {$context}: {$error}");
    }
}
