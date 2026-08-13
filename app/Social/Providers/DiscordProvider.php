<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Dto\AuthResultDto;
use App\Social\Dto\AuthUrlDto;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Discord adapter: standard OAuth2 authorization-code flow scoped to
 * `webhook.incoming` (https://discord.com/developers/docs/topics/oauth2#webhook-authorization-code-grant),
 * which is Discord's own documented mechanism for "let a third-party app
 * post into one channel" -- the consent screen asks the user to pick a
 * channel, and the token exchange hands back a ready-to-use webhook
 * (id/token/url) instead of a general-purpose bot access token used for
 * further API calls.
 *
 * The webhook URL IS the credential (it authenticates by inclusion, no
 * separate header) -- it is what this class stores as `Integration::$token`,
 * exactly as the class docblock for the ordinary OAuth access-token case
 * elsewhere in this app, and it must never appear in a log/exception the
 * way any other token must not (see send()'s docblock).
 *
 * Host re-validation (Task 14 review, Finding 1's pattern, applied even
 * though this value is not literally typed by an admin at connect time):
 * the webhook url arrives from Discord's own token-exchange response,
 * not directly from request input, so it is not the same SSRF shape as
 * MastodonProvider's user-typed instance host. Still, webhookUrlFor()
 * enforces both SafeRemoteHost::assertSafeUrl() AND an explicit
 * `discord.com` host check on every use (connect time AND every
 * publish), as defense in depth against a future manual-paste flow, a
 * compromised/tampered stored value, or a change to Discord's response
 * shape -- cheap to check, and it directly closes off "this credential
 * silently becomes an SSRF/arbitrary-webhook primitive" for good.
 *
 * No threading (`supportsThreads = false`): a webhook's `POST
 * /webhooks/{id}/{token}` has no reply/message_reference support, so a
 * multi-segment chain publishes as separate, unrelated consecutive
 * messages -- see post()'s docblock for what this means for
 * $replyToReleaseId specifically.
 */
class DiscordProvider implements SocialProviderInterface
{
    use MapsHttpErrors;

    public function identifier(): string
    {
        return 'discord';
    }

    public function name(): string
    {
        return 'Discord';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 2000,
            maxImages: 10,
            maxVideos: 10,
            supportsThreads: false,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4'],
            maxFileSizeBytes: 8 * 1024 * 1024,
            allowsMixedMedia: true,
            maxThreadLength: null,
            requiresAltText: false,
        );
    }

    /**
     * $params is accepted (per SocialProviderInterface) but unused:
     * Discord needs no per-connect-attempt configuration ahead of the
     * redirect -- client_id/scope are fixed, from config, not request
     * input.
     *
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        $clientId = (string) config('services.discord.client_id');

        $query = http_build_query([
            'client_id' => $clientId,
            'scope' => 'webhook.incoming',
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);

        return new AuthUrlDto(
            url: "https://discord.com/oauth2/authorize?{$query}",
            extra: [],
        );
    }

    /**
     * Exchanges the authorization code for the channel webhook Discord's
     * consent screen let the user pick. webhookUrlFor() re-validates the
     * returned url before it is ever trusted -- see this class's
     * docblock.
     *
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        $code = $params['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new InvalidArgumentException('Discord requires an authorization code.');
        }

        $body = $this->call(
            fn (): Response => Http::asForm()->post('https://discord.com/api/oauth2/token', [
                'grant_type' => 'authorization_code',
                'client_id' => (string) config('services.discord.client_id'),
                'client_secret' => (string) config('services.discord.client_secret'),
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]),
            'exchanging the authorization code',
        );

        $webhook = $body['webhook'] ?? null;

        if (! is_array($webhook) || ! isset($webhook['id'], $webhook['channel_id'], $webhook['url'])) {
            throw new ProviderPermanentException('Discord token exchange did not include a channel webhook.');
        }

        $url = $this->validatedWebhookUrl((string) $webhook['url']);

        return new AuthResultDto(
            internalId: (string) $webhook['channel_id'],
            name: is_string($webhook['name'] ?? null) && $webhook['name'] !== '' ? (string) $webhook['name'] : 'Discord channel',
            token: $url,
            settings: [
                'channel_id' => (string) $webhook['channel_id'],
                'guild_id' => (string) ($webhook['guild_id'] ?? ''),
            ],
        );
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $webhookUrl = $this->webhookUrlFor($integration);

        $results = [];

        foreach ($chain as $index => $payload) {
            // Discord webhooks cannot reply to an arbitrary message (no
            // message_reference support on the execute-webhook
            // endpoint), and this provider does not even link its own
            // consecutive segments to one another (see the class
            // docblock) -- so there is nothing $replyToReleaseId could
            // possibly attach to here. Per SocialProviderInterface::post()'s
            // contract, a provider that cannot honour the anchor must
            // refuse outright rather than silently publish a detached
            // message under the caller's mistaken assumption that it
            // threaded.
            if ($index === 0 && $payload->replyToReleaseId !== null) {
                throw new ProviderPermanentException(
                    'Discord webhooks cannot reply to a specific message; a resumed chain cannot be anchored here.',
                );
            }

            try {
                $result = $this->publishSegment($webhookUrl, $payload);
            } catch (ProviderException $e) {
                if ($results !== []) {
                    throw new ProviderPartialFailureException($e->getMessage(), $results, $e);
                }

                throw $e;
            }

            $results[$index] = $result;
        }

        return $results;
    }

    private function publishSegment(string $webhookUrl, PostPayloadDto $payload): PostResultDto
    {
        if ($payload->media === []) {
            $body = $this->call(
                fn (): Response => Http::post("{$webhookUrl}?wait=true", ['content' => $payload->content]),
                'executing the webhook',
            );

            return $this->resultFromExecution($body);
        }

        $request = Http::asMultipart();

        foreach ($payload->media as $index => $media) {
            $request = $request->attach("files[{$index}]", (string) file_get_contents($media->path), basename($media->path));
        }

        $body = $this->call(
            fn (): Response => $request->post("{$webhookUrl}?wait=true", [
                'payload_json' => (string) json_encode(['content' => $payload->content]),
            ]),
            'executing the webhook',
        );

        return $this->resultFromExecution($body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function resultFromExecution(array $body): PostResultDto
    {
        $id = $body['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            throw new ProviderPermanentException('Discord webhook execution did not return a message id.');
        }

        return new PostResultDto(releaseId: (string) $id);
    }

    /**
     * Resolves the stored webhook url for an already-connected channel
     * AND re-validates it on every use -- see this class's docblock.
     * Mapped to ProviderPermanentException: retrying will not change
     * what the stored value currently is, and a human must reconnect
     * the channel.
     */
    private function webhookUrlFor(Integration $integration): string
    {
        $token = $integration->token;

        if (! is_string($token) || $token === '') {
            throw new ProviderPermanentException('This Discord channel has no webhook url recorded.');
        }

        try {
            return $this->validatedWebhookUrl($token);
        } catch (ProviderPermanentException $e) {
            throw new ProviderPermanentException(
                "This Discord channel's webhook url is no longer safe to use: {$e->getMessage()}",
            );
        }
    }

    private function validatedWebhookUrl(string $url): string
    {
        try {
            $host = SafeRemoteHost::assertSafeUrl($url);
        } catch (UnsafeRemoteHostException $e) {
            throw new ProviderPermanentException("Discord webhook url is not safe to use: {$e->getMessage()}");
        }

        if ($host !== 'discord.com' && ! str_ends_with($host, '.discord.com')) {
            throw new ProviderPermanentException("Discord webhook url host [{$host}] is not a discord.com host.");
        }

        return $url;
    }

    /**
     * Runs one Discord API call, translating both an unsuccessful HTTP
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
     * $previous (Task 14 review, Finding 2): every Discord webhook call
     * this provider makes embeds the webhook token in the URL PATH
     * (`https://discord.com/api/webhooks/{id}/{token}`), exactly the
     * TelegramProvider::send() shape this policy was written for. See
     * that method's docblock for the fuller rationale.
     */
    private function send(Closure $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new ProviderTransientException('Network error while calling the Discord API.');
        }

        return $response;
    }

    /**
     * Discord reports errors via plain HTTP status codes plus its own
     * JSON body (`message`, sometimes `code`/`errors`) -- leans on the
     * shared table in MapsHttpErrors for the status-code decision, and
     * reads the `retry_after` field (seconds, as a float) Discord's rate
     * limiter actually returns rather than a Retry-After header.
     */
    private function errorFor(Response $response, string $context): ProviderException
    {
        $body = $response->json();
        $message = is_array($body) ? (string) ($body['message'] ?? 'unknown error') : 'unknown error';
        $retryAfter = is_array($body) && isset($body['retry_after']) ? (int) ceil((float) $body['retry_after']) : 0;

        return $this->exceptionForStatus(
            $response->status(),
            "Discord API error while {$context} ({$response->status()}): {$message}",
            $retryAfter,
        );
    }
}
