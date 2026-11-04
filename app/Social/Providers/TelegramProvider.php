<?php

namespace App\Social\Providers;

use App\Models\Integration;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SocialProviderInterface;
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
class TelegramProvider implements DeclaresAuthFields, ManualTokenAuth, SocialProviderInterface
{
    use BoundsHttpTimeouts, MapsHttpErrors, ReadsMediaFiles, TranslatesMarkup;

    private const string API_BASE = 'https://api.telegram.org';

    public function identifier(): string
    {
        return 'telegram';
    }

    public function name(): string
    {
        return 'Telegram';
    }

    /**
     * A bot token plus the chat to post into — this provider has no OAuth
     * flow at all (ManualTokenAuth), so these go straight to the callback.
     * See DeclaresAuthFields.
     *
     * @return list<array{key: string, type: 'text'|'password'}>
     */
    public function authFields(): array
    {
        return [
            ['key' => 'bot_token', 'type' => 'password'],
            ['key' => 'chat_id', 'type' => 'text'],
        ];
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
            // Every mark, because Telegram's parse_mode=HTML renders
            // all five (<b>, <i>, <s>, <code>, <a href>). See
            // TranslatesMarkup: this provider only sets parse_mode on
            // messages that actually carry markup, so a plain post
            // goes out exactly as it did before formatting existed.
            supportedMarks: TextMark::cases(),
            requiresMedia: false,
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

        $body = $this->api()->json(fn (): Response => $this->http()->get(
            $this->apiUrl($botToken, 'getChat'),
            ['chat_id' => $chatId],
        ), 'verifying credentials', auth: true);

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
        // Null means "this body has no markup at all" -- array_filter
        // then drops both fields and the request is byte-for-byte the
        // one this method sent before formatting existed. That matters
        // beyond tidiness: under parse_mode=HTML Telegram answers 400
        // for a stray '<', so a post reading "5 < 6" would stop
        // publishing the day the parse mode became unconditional.
        $html = $this->markupAsHtml($text);

        $body = $this->api()->json(
            fn (): Response => $this->http()->asForm()->post($this->apiUrl($token, 'sendMessage'), array_filter([
                'chat_id' => $chatId,
                'text' => $html ?? $text,
                'parse_mode' => $html === null ? null : 'HTML',
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

        // A caption is post content like any other: formatting that
        // works in a text post and dies on a post with a picture is a
        // bug users would report as "bold randomly stops working".
        $captionHtml = $this->markupAsHtml($caption);

        $body = $this->api()->json(
            fn (): Response => $this->uploadHttp()->attach($field, $this->fileStream($media), $media->filename(), $this->fileHeaders($media))
                ->post($this->apiUrl($token, $method), array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption !== '' ? ($captionHtml ?? $caption) : null,
                    'parse_mode' => $caption !== '' && $captionHtml !== null ? 'HTML' : null,
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
            $request = $request->attach($field, $this->fileStream($media), $media->filename(), $this->fileHeaders($media));

            $item = [
                'type' => str_starts_with($media->mimeType, 'video/') ? 'video' : 'photo',
                'media' => "attach://{$field}",
            ];

            if ($index === 0 && $caption !== '') {
                // sendMediaGroup takes no top-level parse_mode -- it
                // belongs to the InputMedia item that carries the
                // caption, and nowhere else.
                $captionHtml = $this->markupAsHtml($caption);
                $item['caption'] = $captionHtml ?? $caption;

                if ($captionHtml !== null) {
                    $item['parse_mode'] = 'HTML';
                }
            }

            $inputMedia[] = $item;
        }

        $body = $this->api()->json(
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

    private function api(): ProviderCall
    {
        return new ProviderCall($this->name(), new TelegramErrors);
    }

    private function apiUrl(string $token, string $method): string
    {
        return self::API_BASE."/bot{$token}/{$method}";
    }
}
