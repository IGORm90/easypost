<?php

use App\Models\Integration;
use App\Social\Contracts\SupportsOptions;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Exceptions\UnknownProviderOptionException;
use App\Social\ProviderRegistry;
use App\Social\Providers\SlackProvider;
use App\Support\SafeRemoteHost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Task 15: standard OAuth2 (chat:write, files:write, channels:read).
 * Unlike Telegram/Mastodon's "reply to the immediately previous segment"
 * threading, Slack threads are flat: every reply's `thread_ts` points at
 * the THREAD ROOT, not at the previous reply -- see SlackProvider::post()
 * for how that changes the within-chain bookkeeping versus the other
 * four providers.
 *
 * The channel to publish to is a per-channel setting
 * (settings.channel_id) populated via the generic SupportsOptions hook
 * (GET /api/integrations/{id}/options/channels), backed here by
 * conversations.list.
 *
 * DNS is faked for every test via SafeRemoteHost::fake() (same
 * convention as MastodonProviderTest/DiscordProviderTest) so
 * uploadMedia()'s host-pin check on the files.slack.com upload url
 * (Task 15 review, Finding 3) never touches real DNS/network.
 */

beforeEach(function () {
    SafeRemoteHost::fake(['files.slack.com' => ['198.51.100.30']]);
});

afterEach(function () {
    SafeRemoteHost::stopFaking();
});

/**
 * @return array<string, mixed>
 */
function slackFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/social/{$name}.json")), true);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function slackIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'slack',
        'token' => 'xoxb-slack-bot-token-abc123',
        'internal_id' => 'T9TK3CUKW',
        'settings' => ['channel_id' => 'C0EAQDV4Z'],
    ], $overrides));
}

// --- registry --------------------------------------------------------------

it('resolves slack from the provider registry', function () {
    $provider = app(ProviderRegistry::class)->get('slack');

    expect($provider)->toBeInstanceOf(SlackProvider::class)
        ->and($provider->identifier())->toBe('slack')
        ->and($provider)->toBeInstanceOf(SupportsOptions::class);
});

// --- generateAuthUrl() / authenticate() -------------------------------------

it('builds an authorize url with the required scopes', function () {
    config(['services.slack.integration.client_id' => 'slack-client-id']);

    $provider = app(ProviderRegistry::class)->get('slack');
    $authUrl = $provider->generateAuthUrl('csrf-state', 'https://app.test/callback');

    expect($authUrl->url)->toStartWith('https://slack.com/oauth/v2/authorize?')
        ->and($authUrl->url)->toContain('client_id=slack-client-id')
        ->and($authUrl->url)->toContain('scope=chat%3Awrite%2Cfiles%3Awrite%2Cchannels%3Aread')
        ->and($authUrl->url)->toContain('state=csrf-state');
});

it('completes authentication by exchanging the code for a bot token', function () {
    config(['services.slack.integration.client_id' => 'slack-client-id', 'services.slack.integration.client_secret' => 'slack-client-secret']);

    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response(slackFixture('slack_oauth_access')),
    ]);

    $provider = app(ProviderRegistry::class)->get('slack');
    $result = $provider->authenticate(['code' => 'auth-code'], 'https://app.test/callback');

    expect($result->internalId)->toBe('T9TK3CUKW')
        ->and($result->name)->toBe('Scheduler Workspace')
        ->and($result->token)->toBe('xoxb-slack-bot-token-abc123');
});

it('maps an authentication rejection to an auth exception', function () {
    config(['services.slack.integration.client_id' => 'slack-client-id', 'services.slack.integration.client_secret' => 'slack-client-secret']);

    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response(slackFixture('slack_error_invalid_auth')),
    ]);

    $provider = app(ProviderRegistry::class)->get('slack');

    expect(fn () => $provider->authenticate(['code' => 'bad-code'], 'https://app.test/callback'))
        ->toThrow(ProviderAuthException::class);
});

// --- options() (SupportsOptions) --------------------------------------------

it('lists channels via SupportsOptions', function () {
    Http::fake([
        'slack.com/api/conversations.list*' => Http::response(slackFixture('slack_conversations_list')),
    ]);

    $integration = slackIntegration();
    $provider = app(ProviderRegistry::class)->get('slack');
    expect($provider)->toBeInstanceOf(SlackProvider::class);
    /** @var SlackProvider $provider */
    $options = $provider->options($integration, 'channels');

    expect($options)->toBe([
        ['value' => 'C0EAQDV4Z', 'label' => 'general'],
        ['value' => 'C0EAQDV5A', 'label' => 'random'],
    ]);
});

it('rejects an unrecognised option name', function () {
    $integration = slackIntegration();
    $provider = app(ProviderRegistry::class)->get('slack');
    expect($provider)->toBeInstanceOf(SlackProvider::class);
    /** @var SlackProvider $provider */
    expect(fn () => $provider->options($integration, 'boards'))
        ->toThrow(UnknownProviderOptionException::class);
});

// --- uploadMedia() -----------------------------------------------------

it('uploads media via the three-step external upload flow and returns a MediaDto carrying the file id', function () {
    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response(slackFixture('slack_get_upload_url')),
        'files.slack.com/upload/v1/*' => Http::response(['ok' => true]),
    ]);

    $integration = slackIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png');
    $provider = app(ProviderRegistry::class)->get('slack');
    expect($provider)->toBeInstanceOf(SlackProvider::class);
    /** @var SlackProvider $provider */
    $uploaded = $provider->uploadMedia($integration, $media);

    expect($uploaded->remoteId)->toBe('F0123ABCXYZ');
});

// --- Task 15 review, Finding 3: the upload url returned by
// files.getUploadURLExternal is a network target exactly like Discord's
// webhook url, and must be pinned to a slack.com host the same way.

it('rejects an upload url that does not point at a slack.com host', function () {
    $tampered = slackFixture('slack_get_upload_url');
    $tampered['upload_url'] = 'https://evil.internal/steal-this';

    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response($tampered),
    ]);

    $integration = slackIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png');
    $provider = app(ProviderRegistry::class)->get('slack');
    expect($provider)->toBeInstanceOf(SlackProvider::class);
    /** @var SlackProvider $provider */
    expect(fn () => $provider->uploadMedia($integration, $media))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.internal'));
});

// --- post(): happy path --------------------------------------------------

it('publishes a message and returns the ts as the release id', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_post_message')),
    ]);

    $integration = slackIntegration();
    $result = app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('hello')]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->releaseId)->toBe('1503435956.000247');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
        && $request['channel'] === 'C0EAQDV4Z'
        && $request['text'] === 'hello'
        && ! isset($request['thread_ts']));
});

// --- Task 15 review, Minor 3: a media-only post (no caption) must not
// be rejected by Slack's `no_text` validation -- Task 25's editor will
// let users compose exactly this.

it('publishes a media-only post (empty content) without tripping Slack\'s no_text rejection', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_post_message')),
        'slack.com/api/files.completeUploadExternal' => Http::response(slackFixture('slack_complete_upload')),
    ]);

    $integration = slackIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png', remoteId: 'F0123ABCXYZ');
    $result = app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('', [$media])]);

    expect($result)->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
        && $request['text'] !== '');
});

it('publishes a thread where every reply anchors to the thread root ts, not the previous reply', function () {
    Http::fakeSequence('slack.com/api/chat.postMessage')
        ->push(slackFixture('slack_post_message'))
        ->push(slackFixture('slack_post_message_reply'));

    $integration = slackIntegration();
    $chain = [new PostPayloadDto('first segment'), new PostPayloadDto('second segment')];
    $result = app(ProviderRegistry::class)->get('slack')->post($integration, $chain);

    expect($result)->toHaveCount(2)
        ->and($result[0]->releaseId)->toBe('1503435956.000247')
        ->and($result[1]->releaseId)->toBe('1503435957.000300');

    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), 'chat.postMessage') && ! isset($request['thread_ts']),
        fn ($request) => str_contains($request->url(), 'chat.postMessage') && $request['thread_ts'] === '1503435956.000247',
    ]);
});

it('publishes media by sharing pre-uploaded files alongside the message', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_post_message')),
        'slack.com/api/files.completeUploadExternal' => Http::response(slackFixture('slack_complete_upload')),
    ]);

    $integration = slackIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png', remoteId: 'F0123ABCXYZ');
    app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('with media', [$media])]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'files.completeUploadExternal')
        && $request['channel_id'] === 'C0EAQDV4Z'
        && $request['thread_ts'] === '1503435956.000247');
});

// --- Task 15 review, Finding 1 (CRITICAL): chat.postMessage genuinely
// publishes a live message the instant it succeeds -- a subsequent
// files.completeUploadExternal failure must never cause that published
// message to be lost from $published (which would make a pipeline retry
// post it a second time, and would orphan it forever if this had been
// the last chain segment).

it('records the published text message even when attaching its media fails, instead of losing it', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_post_message')),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => false, 'error' => 'internal_error'], 503),
    ]);

    $integration = slackIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png', remoteId: 'F0123ABCXYZ');

    try {
        app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('with media', [$media])]);
        test()->fail('Expected ProviderPartialFailureException.');
    } catch (ProviderPartialFailureException $e) {
        // The text message's own ts is in $published -- the pipeline's
        // documented contract (ProviderPartialFailureException's
        // docblock) is to persist every index present here and never
        // retry/re-post it, which is exactly what keeps a retry from
        // duplicating this message.
        expect($e->published)->toHaveCount(1)
            ->and($e->published[0]->releaseId)->toBe('1503435956.000247');
    }

    // Exactly one chat.postMessage call happened -- the invariant this
    // finding asked to be tested: a retry (which, per the pipeline
    // contract above, would never re-include an index already present
    // in $published) cannot produce a second visible message, because
    // the provider never told the pipeline this one was missing.
    Http::assertSentCount(2); // chat.postMessage + the failed completeUploadExternal attempt
});

it('records every already-published segment, including a media-attach failure on a later segment', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::sequence()
            ->push(slackFixture('slack_post_message'))
            ->push(slackFixture('slack_post_message_reply')),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => false, 'error' => 'internal_error'], 503),
    ]);

    $integration = slackIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png', remoteId: 'F0123ABCXYZ');
    $chain = [new PostPayloadDto('first, text only'), new PostPayloadDto('second, with media', [$media])];

    try {
        app(ProviderRegistry::class)->get('slack')->post($integration, $chain);
        test()->fail('Expected ProviderPartialFailureException.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveCount(2)
            ->and($e->published[0]->releaseId)->toBe('1503435956.000247')
            ->and($e->published[1]->releaseId)->toBe('1503435957.000300');
    }
});

it('rejects publishing media that was never pre-uploaded', function () {
    Http::fake();

    $integration = slackIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png');

    expect(fn () => app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('with media', [$media])]))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNothingSent();
});

it('refuses to publish without a configured channel', function () {
    Http::fake();

    $integration = slackIntegration(['settings' => []]);

    expect(fn () => app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNothingSent();
});

// --- error mapping -----------------------------------------------------

it('rejects content over the slack character limit with a permanent exception', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_error_msg_too_long')),
    ]);

    $integration = slackIntegration();
    $tooLong = str_repeat('a', 40001);

    expect(fn () => app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto($tooLong)]))
        ->toThrow(ProviderPermanentException::class);
});

it('maps a slack rate-limited response to a rate limit exception', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_error_ratelimited'), 429, ['Retry-After' => '20']),
    ]);

    $integration = slackIntegration();

    try {
        app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('hello')]);
        test()->fail('Expected ProviderRateLimitException.');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(20);
    }
});

it('maps a slack invalid_auth response to an auth exception', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_error_invalid_auth')),
    ]);

    $integration = slackIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderAuthException::class);
});

it('maps a slack 5xx to a transient exception', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_error_server'), 503),
    ]);

    $integration = slackIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderTransientException::class);
});

// --- thread resumption + partial failure --------------------------------

it('attaches a resumed chain to the given reply-to anchor instead of starting a new root', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_post_message_reply')),
    ]);

    $integration = slackIntegration();
    $resumedChain = [(new PostPayloadDto('second segment'))->withReplyToReleaseId('1503435956.000247')];
    $result = app(ProviderRegistry::class)->get('slack')->post($integration, $resumedChain);

    expect($result[0]->releaseId)->toBe('1503435957.000300');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
        && $request['thread_ts'] === '1503435956.000247');
});

it('throws a partial failure exception carrying already-published segments when a later segment fails', function () {
    Http::fakeSequence('slack.com/api/chat.postMessage')
        ->push(slackFixture('slack_post_message'))
        ->push(slackFixture('slack_error_msg_too_long'));

    $integration = slackIntegration();
    $chain = [new PostPayloadDto('first'), new PostPayloadDto('second'), new PostPayloadDto('third')];

    try {
        app(ProviderRegistry::class)->get('slack')->post($integration, $chain);
        test()->fail('Expected ProviderPartialFailureException.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveCount(1)
            ->and($e->published[0]->releaseId)->toBe('1503435956.000247');
    }
});

it('does not throw a partial failure exception when the very first segment fails', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(slackFixture('slack_error_msg_too_long')),
    ]);

    $integration = slackIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('first')]))
        ->toThrow(ProviderPermanentException::class);
});

// --- secret-leak protection ------------------------------------------------

it('does not leak the bot token through the exception chain on a network-level failure', function () {
    $token = 'super-secret-slack-bot-token-abc123';

    Http::fake([
        'slack.com/*' => function () use ($token) {
            throw new ConnectionException(
                "cURL error 6: Could not resolve host for https://slack.com/api/chat.postMessage (Authorization: Bearer {$token})",
            );
        },
    ]);

    $integration = slackIntegration(['token' => $token]);

    try {
        app(ProviderRegistry::class)->get('slack')->post($integration, [new PostPayloadDto('hello')]);
        test()->fail('Expected ProviderTransientException.');
    } catch (ProviderTransientException $e) {
        $messages = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        expect($messages)->not->toBeEmpty();

        foreach ($messages as $message) {
            expect($message)->not->toContain($token);
        }
    }
});
