<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SocialProviderInterface;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * Telegram Bot API adapter. Not OAuth: a Telegram channel is connected
 * by the user pasting a bot token (created via @BotFather) and the
 * numeric chat id of the channel/group that bot has been made an admin
 * of -- see ManualTokenAuth, which this class implements so `GET
 * /api/providers` reports `auth_type: 'manual'` and the frontend (Task
 * 17) renders a credential form instead of an OAuth popup.
 *
 * generateAuthUrl() is still implemented (every provider must, per
 * SocialProviderInterface) but does no network call and its `url` is
 * never followed by a manual-token frontend flow -- see ManualTokenAuth's
 * docblock. authenticate() is where the real verification happens: it
 * calls `getChat` with the supplied credentials and only returns
 * successfully if Telegram confirms the bot can see that chat.
 *
 * Threading: Telegram's `reply_to_message_id` addresses a message by id
 * within the same chat, which is exactly what PostPayloadDto's chain
 * threading (segment N replies to segment N-1) and the cross-call
 * `$replyToReleaseId` resumption anchor both need -- Telegram can always
 * honour a resumption anchor (no ProviderPermanentException refusal
 * branch is needed here, unlike networks that can't reply to an
 * arbitrary external post id).
 */
class TelegramProvider implements ManualTokenAuth, SocialProviderInterface
{
    use BoundsHttpTimeouts, MapsHttpErrors;

    private const string API_BASE = 'https://api.telegram.org';

    public function identifier(): string
    {
        return 'telegram';
    }

    public function name(): string
    {
        return 'Telegram';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            maxCharacters: 4096,
            maxImages: 10,
            maxVideos: 10,
            supportsThreads: true,
            supportsScheduling: false,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4'],
            maxFileSizeBytes: 50 * 1024 * 1024,
            allowsMixedMedia: true,
            maxThreadLength: null,
            requiresAltText: false,
        );
    }

    /**
     * No redirect flow exists for a manual-token provider -- this exists
     * only to satisfy the interface uniformly (see ManualTokenAuth's
     * docblock: the frontend never follows this URL for a provider
     * implementing that marker). $params is accepted but unused: Telegram
     * needs nothing ahead of time, the bot token and chat id are supplied
     * directly to authenticate().
     *
     * @param  array<string, mixed>  $params
     */
    public function generateAuthUrl(string $state, string $redirectUri, array $params = []): AuthUrlDto
    {
        return new AuthUrlDto(
            url: 'https://core.telegram.org/bots#how-do-i-create-a-bot',
            extra: ['state' => $state],
        );
    }

    /**
     * Verifies a bot token + chat id pair by calling Telegram's `getChat`.
     * Any rejection Telegram gives us here -- unknown/revoked token,
     * chat not found, bot never added to the chat -- means the supplied
     * credentials cannot be used, so (unlike post()'s finer-grained
     * mapping) every non-transient failure becomes ProviderAuthException:
     * there is no "content" for a malformed request to be about at this
     * stage, only the credential itself.
     *
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $params, string $redirectUri): AuthResultDto
    {
        $botToken = $params['bot_token'] ?? null;
        $chatId = $params['chat_id'] ?? null;

        if (! is_string($botToken) || $botToken === '') {
            throw new InvalidArgumentException('Telegram requires a non-empty bot_token.');
        }

        if (! (is_string($chatId) || is_int($chatId)) || (string) $chatId === '') {
            throw new InvalidArgumentException('Telegram requires a non-empty chat_id.');
        }

        $chatId = (string) $chatId;

        $response = $this->send(fn (): Response => $this->http()->get(
            $this->apiUrl($botToken, 'getChat'),
            ['chat_id' => $chatId],
        ));

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw $this->authErrorFor($response);
        }

        /** @var array<string, mixed> $chat */
        $chat = $body['result'];
        $name = $chat['title'] ?? $chat['username'] ?? "Telegram chat {$chatId}";

        return new AuthResultDto(
            internalId: $chatId,
            name: (string) $name,
            token: $botToken,
            settings: ['chat_id' => $chatId],
        );
    }

    /**
     * @param  PostPayloadDto[]  $chain
     * @return array<int, PostResultDto>
     */
    public function post(Integration $integration, array $chain): array
    {
        $token = (string) $integration->token;
        $chatId = (string) ($integration->settings['chat_id'] ?? $integration->internal_id);

        $results = [];
        $previousMessageId = null;

        foreach ($chain as $index => $payload) {
            $replyTo = $index === 0
                ? ($payload->replyToReleaseId !== null ? (int) $payload->replyToReleaseId : null)
                : $previousMessageId;

            try {
                $result = $this->publishSegment($token, $chatId, $payload, $replyTo);
            } catch (ProviderException $e) {
                if ($results !== []) {
                    throw new ProviderPartialFailureException($e->getMessage(), $results, $e);
                }

                throw $e;
            }

            $results[$index] = $result;
            $previousMessageId = (int) $result->releaseId;
        }

        return $results;
    }

    private function publishSegment(string $token, string $chatId, PostPayloadDto $payload, ?int $replyTo): PostResultDto
    {
        $media = $payload->media;

        if ($media === []) {
            return $this->sendMessage($token, $chatId, $payload->content, $replyTo);
        }

        if (count($media) === 1) {
            return $this->sendSingleMedia($token, $chatId, $media[0], $payload->content, $replyTo);
        }

        return $this->sendMediaGroup($token, $chatId, $media, $payload->content, $replyTo);
    }

    private function sendMessage(string $token, string $chatId, string $text, ?int $replyTo): PostResultDto
    {
        $body = $this->call(
            fn (): Response => $this->http()->asForm()->post($this->apiUrl($token, 'sendMessage'), array_filter([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_to_message_id' => $replyTo,
            ], static fn (mixed $value): bool => $value !== null)),
            'sending a message',
        );

        return $this->resultFromMessage($body);
    }

    private function sendSingleMedia(string $token, string $chatId, MediaDto $media, string $caption, ?int $replyTo): PostResultDto
    {
        $isVideo = str_starts_with($media->mimeType, 'video/');
        $method = $isVideo ? 'sendVideo' : 'sendPhoto';
        $field = $isVideo ? 'video' : 'photo';

        $body = $this->call(
            fn (): Response => $this->uploadHttp()->attach($field, (string) file_get_contents($media->path), basename($media->path))
                ->post($this->apiUrl($token, $method), array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption !== '' ? $caption : null,
                    'reply_to_message_id' => $replyTo,
                ], static fn (mixed $value): bool => $value !== null)),
            'sending media',
        );

        return $this->resultFromMessage($body);
    }

    /**
     * @param  MediaDto[]  $mediaList
     */
    private function sendMediaGroup(string $token, string $chatId, array $mediaList, string $caption, ?int $replyTo): PostResultDto
    {
        $request = $this->uploadHttp()->asMultipart();
        $inputMedia = [];

        foreach ($mediaList as $index => $media) {
            $field = "media{$index}";
            $request = $request->attach($field, (string) file_get_contents($media->path), basename($media->path));

            $item = [
                'type' => str_starts_with($media->mimeType, 'video/') ? 'video' : 'photo',
                'media' => "attach://{$field}",
            ];

            if ($index === 0 && $caption !== '') {
                $item['caption'] = $caption;
            }

            $inputMedia[] = $item;
        }

        $body = $this->call(
            fn (): Response => $request->post($this->apiUrl($token, 'sendMediaGroup'), array_filter([
                'chat_id' => $chatId,
                'media' => json_encode($inputMedia),
                'reply_to_message_id' => $replyTo,
            ], static fn (mixed $value): bool => $value !== null)),
            'sending a media group',
        );

        $messages = $body['result'] ?? null;

        if (! is_array($messages) || ! isset($messages[0]['message_id'])) {
            throw new ProviderPermanentException('Telegram sendMediaGroup returned no messages.');
        }

        return new PostResultDto(releaseId: (string) $messages[0]['message_id']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function resultFromMessage(array $body): PostResultDto
    {
        $messageId = $body['result']['message_id'] ?? null;

        if ($messageId === null) {
            throw new ProviderPermanentException('Telegram response did not include a message_id.');
        }

        return new PostResultDto(releaseId: (string) $messageId);
    }

    /**
     * Runs one Telegram API call, translating both an unsuccessful HTTP
     * response and a network-level failure into the App\Social\Exceptions
     * taxonomy, and returns the decoded JSON body. $context is a short,
     * human-readable phrase (no credentials in it) used in exception
     * messages.
     *
     * @return array<string, mixed>
     */
    private function call(Closure $call, string $context): array
    {
        $response = $this->send($call);
        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw $this->errorFor($response, $context);
        }

        return $body;
    }

    /**
     * Deliberately does NOT attach the caught ConnectionException as
     * $previous (Task 14 review, Finding 2): every Telegram API call
     * this provider makes embeds the bot token in the URL PATH
     * (`https://api.telegram.org/bot{token}/{method}`), and Guzzle's
     * connection-exception message conventionally includes the full
     * request URI. The top-level ProviderTransientException message
     * here is already token-free, but chaining the original exception
     * would let any logger/error-tracker that serialises the full
     * exception chain (Sentry, a Monolog handler that walks
     * getPrevious()...) print a live bot token -- exactly the "tokens
     * must never appear in ... thrown context" constraint. A generic,
     * previous-less exception is the fix; a redacted message would still
     * require trusting every future Guzzle version's message format not
     * to change shape.
     */
    private function send(Closure $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new ProviderTransientException('Network error while calling the Telegram API.');
        }

        return $this->refuseRedirect($response, $this->name());
    }

    /**
     * post()-context mapping: distinguishes a revoked/invalid token, a
     * bot removed from the chat, a malformed request and a rate limit as
     * four different outcomes (task-14-brief.md's explicit requirement),
     * using the shared status-code table from MapsHttpErrors for the
     * parts that generalise (429, 5xx) and Telegram's own error envelope
     * for the retry-after hint.
     */
    private function errorFor(Response $response, string $context): ProviderException
    {
        $body = $response->json();
        $description = is_array($body) ? (string) ($body['description'] ?? 'unknown error') : 'unknown error';
        $errorCode = is_array($body) && isset($body['error_code']) ? (int) $body['error_code'] : $response->status();
        $retryAfter = is_array($body) ? (int) data_get($body, 'parameters.retry_after', 0) : 0;

        return $this->exceptionForStatus($errorCode, "Telegram API error while {$context}: {$description}", $retryAfter);
    }

    /**
     * authenticate()-context mapping: unlike errorFor(), every
     * non-transient rejection (400 bad chat id, 401 revoked token, 403
     * bot never added to the chat, 404 chat not found) means the same
     * thing here -- these credentials do not work -- so they all become
     * ProviderAuthException rather than being split by status code.
     */
    private function authErrorFor(Response $response): ProviderException
    {
        $body = $response->json();
        $description = is_array($body) ? (string) ($body['description'] ?? 'unknown error') : 'unknown error';
        $errorCode = is_array($body) && isset($body['error_code']) ? (int) $body['error_code'] : $response->status();
        $retryAfter = is_array($body) ? (int) data_get($body, 'parameters.retry_after', 0) : 0;

        if ($errorCode === 429) {
            return new ProviderRateLimitException("Telegram rate-limited chat verification: {$description}", $retryAfter);
        }

        if ($errorCode >= 500) {
            return new ProviderTransientException("Telegram API error while verifying credentials: {$description}");
        }

        return new ProviderAuthException("Telegram rejected the provided bot token/chat id: {$description}");
    }

    private function apiUrl(string $token, string $method): string
    {
        return self::API_BASE."/bot{$token}/{$method}";
    }
}
