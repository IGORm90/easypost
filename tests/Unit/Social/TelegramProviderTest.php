<?php

use App\Models\Integration;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use App\Social\Providers\TelegramProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Task 14: the first real (non-fake) SocialProviderInterface
 * implementation. Telegram is a ManualTokenAuth provider (bot token +
 * chat id, not OAuth) — see TelegramProvider's class docblock.
 */

/**
 * @return array<string, mixed>
 */
function telegramFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/social/{$name}.json")), true);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function telegramIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'telegram',
        'token' => 'bot-token-123:AAAABBBBCCCC',
        'internal_id' => '-1001234567890',
        'settings' => ['chat_id' => '-1001234567890'],
    ], $overrides));
}

/**
 * The value of one field of a multipart request body, as Laravel's HTTP
 * client records it (a list of `['name' => …, 'contents' => …]`). The
 * media-carrying Telegram calls are multipart, so their fields cannot be
 * read off `$request['field']` the way the form-encoded ones can.
 *
 * @param  array<int, array<string, mixed>>  $data
 */
function multipartField(array $data, string $name): ?string
{
    foreach ($data as $field) {
        if (($field['name'] ?? null) === $name) {
            return is_string($field['contents']) ? $field['contents'] : null;
        }
    }

    return null;
}

// --- registry + auth_type -------------------------------------------------

it('resolves telegram from the provider registry', function () {
    $provider = app(ProviderRegistry::class)->get('telegram');

    expect($provider)->toBeInstanceOf(TelegramProvider::class)
        ->and($provider->identifier())->toBe('telegram')
        ->and($provider)->toBeInstanceOf(ManualTokenAuth::class);
});

// --- authenticate() --------------------------------------------------------

it('authenticates by verifying the bot token and chat id via getChat', function () {
    Http::fake([
        'api.telegram.org/bot*/getChat*' => Http::response(telegramFixture('telegram_get_chat')),
    ]);

    $provider = app(ProviderRegistry::class)->get('telegram');
    $result = $provider->authenticate(['bot_token' => 'bot-token-123:AAAA', 'chat_id' => '-1001234567890'], 'https://app.test/callback');

    expect($result->internalId)->toBe('-1001234567890')
        ->and($result->name)->toBe('My Announcements Channel')
        ->and($result->token)->toBe('bot-token-123:AAAA')
        ->and($result->settings)->toBe(['chat_id' => '-1001234567890']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'getChat')
        && $request['chat_id'] === '-1001234567890');
});

it('rejects a bad bot token/chat id with a provider auth exception', function () {
    Http::fake([
        'api.telegram.org/bot*/getChat*' => Http::response(telegramFixture('telegram_error_unauthorized'), 401),
    ]);

    $provider = app(ProviderRegistry::class)->get('telegram');

    expect(fn () => $provider->authenticate(['bot_token' => 'bad-token', 'chat_id' => '123'], 'https://app.test/callback'))
        ->toThrow(ProviderAuthException::class);
});

it('rejects authenticate() with missing credentials before any network call', function () {
    Http::fake();
    $provider = app(ProviderRegistry::class)->get('telegram');

    expect(fn () => $provider->authenticate(['bot_token' => ''], 'https://app.test/callback'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

// --- post(): happy path ------------------------------------------------

it('publishes a telegram message and returns the release id', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_send_message')),
    ]);

    $integration = telegramIntegration();
    $result = app(ProviderRegistry::class)->get('telegram')
        ->post($integration, [new PostPayloadDto('hello')]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->releaseId)->toBe('42');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
        && $request['chat_id'] === '-1001234567890'
        && $request['text'] === 'hello');
});

it('publishes a thread, replying each segment to the previous message id', function () {
    Http::fakeSequence('api.telegram.org/bot*/sendMessage')
        ->push(telegramFixture('telegram_send_message'))
        ->push(telegramFixture('telegram_send_message_reply'));

    $integration = telegramIntegration();
    $chain = [new PostPayloadDto('hello'), new PostPayloadDto('second segment')];
    $result = app(ProviderRegistry::class)->get('telegram')->post($integration, $chain);

    expect($result)->toHaveCount(2)
        ->and($result[0]->releaseId)->toBe('42')
        ->and($result[1]->releaseId)->toBe('43');

    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), 'sendMessage') && ! isset($request['reply_to_message_id']),
        fn ($request) => str_contains($request->url(), 'sendMessage') && $request['reply_to_message_id'] === 42,
    ]);
});

it('publishes a single photo via sendPhoto', function () {
    Http::fake([
        'api.telegram.org/bot*/sendPhoto' => Http::response(telegramFixture('telegram_send_photo')),
    ]);

    $integration = telegramIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png');
    $result = app(ProviderRegistry::class)->get('telegram')
        ->post($integration, [new PostPayloadDto('hello with photo', [$media])]);

    expect($result[0]->releaseId)->toBe('44');
});

it('publishes multiple images via sendMediaGroup', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMediaGroup' => Http::response(telegramFixture('telegram_send_media_group')),
    ]);

    $integration = telegramIntegration();
    $media = [
        new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png'),
        new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png'),
    ];
    $result = app(ProviderRegistry::class)->get('telegram')
        ->post($integration, [new PostPayloadDto('gallery', $media)]);

    expect($result[0]->releaseId)->toBe('45');
});

// --- error mapping -----------------------------------------------------

it('maps a telegram 429 to a rate limit exception carrying retry_after', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_error_rate_limit'), 429),
    ]);

    $integration = telegramIntegration();

    try {
        app(ProviderRegistry::class)->get('telegram')->post($integration, [new PostPayloadDto('hello')]);
        $this->fail('Expected ProviderRateLimitException.');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(30);
    }
});

it('maps a telegram 401 (revoked token) to an auth exception', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_error_unauthorized'), 401),
    ]);

    $integration = telegramIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('telegram')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderAuthException::class);
});

it('maps a telegram 403 (bot kicked from the chat) to an auth exception', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_error_kicked'), 403),
    ]);

    $integration = telegramIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('telegram')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderAuthException::class);
});

it('maps a telegram 400 (malformed request) to a permanent exception', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_error_bad_request'), 400),
    ]);

    $integration = telegramIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('telegram')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderPermanentException::class);
});

it('maps a telegram 5xx to a transient exception', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_error_server'), 500),
    ]);

    $integration = telegramIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('telegram')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderTransientException::class);
});

// --- thread resumption + partial failure --------------------------------

it('attaches a resumed chain to the given reply-to anchor instead of starting a new root', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_send_message_reply')),
    ]);

    $integration = telegramIntegration();
    $resumedChain = [(new PostPayloadDto('second segment'))->withReplyToReleaseId('42')];
    $result = app(ProviderRegistry::class)->get('telegram')->post($integration, $resumedChain);

    expect($result[0]->releaseId)->toBe('43');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
        && $request['reply_to_message_id'] === 42);
});

it('throws a partial failure exception carrying already-published segments when a later segment fails', function () {
    Http::fakeSequence('api.telegram.org/bot*/sendMessage')
        ->push(telegramFixture('telegram_send_message'))
        ->push(telegramFixture('telegram_error_bad_request'), 400);

    $integration = telegramIntegration();
    $chain = [new PostPayloadDto('first'), new PostPayloadDto('second'), new PostPayloadDto('third')];

    try {
        app(ProviderRegistry::class)->get('telegram')->post($integration, $chain);
        test()->fail('Expected ProviderPartialFailureException.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveCount(1)
            ->and($e->published[0]->releaseId)->toBe('42');
    }
});

it('does not throw a partial failure exception when the very first segment fails', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_error_bad_request'), 400),
    ]);

    $integration = telegramIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('telegram')->post($integration, [new PostPayloadDto('first')]))
        ->toThrow(ProviderPermanentException::class);
});

// --- Task 14 review, Finding 2: the bot token must never ride along in
// the exception chain. Guzzle's ConnectionException message conventionally
// embeds the full request URI, and every Telegram API call in this
// provider embeds the bot token in that URI's path
// (https://api.telegram.org/bot{token}/{method}) -- so a naive
// "wrap the caught exception as $previous" would leak a live credential
// to any logger/error-tracker that serialises the whole chain.

it('does not leak the bot token through the exception chain on a network-level failure', function () {
    $token = 'super-secret-bot-token-987654:ZZYYXX';

    Http::fake([
        'api.telegram.org/*' => function () use ($token) {
            // Mirrors the real shape: Guzzle/cURL connection failures
            // report the full request URI, token included, in their
            // message.
            throw new ConnectionException(
                "cURL error 6: Could not resolve host for https://api.telegram.org/bot{$token}/sendMessage",
            );
        },
    ]);

    $integration = telegramIntegration(['token' => $token]);

    try {
        app(ProviderRegistry::class)->get('telegram')->post($integration, [new PostPayloadDto('hello')]);
        test()->fail('Expected ProviderTransientException.');
    } catch (ProviderTransientException $e) {
        $messages = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        // The chain must exist and be more than a fluke of "no previous
        // at all" -- assert on the actual chain contents, not just the
        // absence of an exception.
        expect($messages)->not->toBeEmpty();

        foreach ($messages as $message) {
            expect($message)->not->toContain($token);
        }
    }
});

it('does not leak the bot token through the exception chain when authenticate() hits a network-level failure', function () {
    $token = 'super-secret-bot-token-111222:AABBCC';

    Http::fake([
        'api.telegram.org/*' => function () use ($token) {
            throw new ConnectionException(
                "cURL error 6: Could not resolve host for https://api.telegram.org/bot{$token}/getChat",
            );
        },
    ]);

    try {
        app(ProviderRegistry::class)->get('telegram')->authenticate(['bot_token' => $token, 'chat_id' => '-100'], 'https://app.test/callback');
        test()->fail('Expected ProviderTransientException.');
    } catch (ProviderTransientException $e) {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            expect($current->getMessage())->not->toContain($token);
        }
    }
});

// --- Block 3: markup delivery ---------------------------------------------

it('sends formatted content as html with a parse mode', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_send_message')),
    ]);

    app(ProviderRegistry::class)->get('telegram')
        ->post(telegramIntegration(), [new PostPayloadDto('a **bold** claim & a [link](https://example.test)')]);

    Http::assertSent(fn ($request) => $request['text'] === 'a <b>bold</b> claim &amp; a <a href="https://example.test">link</a>'
        && $request['parse_mode'] === 'HTML');
});

it('sends unformatted content exactly as before, with no parse mode', function () {
    // The regression this whole feature has to not cause: a post with no
    // markup must reach Telegram byte-for-byte as it did before Block 3,
    // ampersands and angle brackets unescaped. Adding parse_mode
    // unconditionally would make Telegram reject "5 < 6" outright.
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response(telegramFixture('telegram_send_message')),
    ]);

    app(ProviderRegistry::class)->get('telegram')
        ->post(telegramIntegration(), [new PostPayloadDto('5 < 6 && 7 > 6')]);

    Http::assertSent(fn ($request) => $request['text'] === '5 < 6 && 7 > 6'
        && ! isset($request['parse_mode']));
});

it('formats a photo caption too', function () {
    // A caption is post content like any other -- formatting that works
    // in a text post and silently dies on a post with an image is worse
    // than no formatting at all.
    Http::fake([
        'api.telegram.org/bot*/sendPhoto' => Http::response(telegramFixture('telegram_send_photo')),
    ]);

    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png');

    app(ProviderRegistry::class)->get('telegram')
        ->post(telegramIntegration(), [new PostPayloadDto('a **bold** caption', [$media])]);

    Http::assertSent(fn ($request) => multipartField($request->data(), 'caption') === 'a <b>bold</b> caption'
        && multipartField($request->data(), 'parse_mode') === 'HTML');
});

it('formats a media group caption too', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMediaGroup' => Http::response(telegramFixture('telegram_send_media_group')),
    ]);

    $media = [
        new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png'),
        new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png'),
    ];

    app(ProviderRegistry::class)->get('telegram')
        ->post(telegramIntegration(), [new PostPayloadDto('a **bold** gallery', $media)]);

    Http::assertSent(function ($request) {
        $payload = json_decode((string) multipartField($request->data(), 'media'), true);

        return is_array($payload)
            && $payload[0]['caption'] === 'a <b>bold</b> gallery'
            && $payload[0]['parse_mode'] === 'HTML';
    });
});
