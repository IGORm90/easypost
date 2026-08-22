<?php

use App\Models\Integration;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\TextMark;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use App\Social\Providers\DiscordProvider;
use App\Support\SafeRemoteHost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Task 15: OAuth2 `webhook.incoming` flow -- connecting a Discord channel
 * means the user consents to a webhook-scoped app, and Discord's token
 * exchange hands back a ready-to-use channel webhook (id/token/url), not
 * a bot access token used for further API calls. Publishing therefore
 * means POSTing directly to that webhook URL, which IS the credential
 * (DiscordProvider::class docblock explains why it's stored as `token`
 * and re-validated on every use the same way MastodonProvider
 * re-validates its instance host).
 *
 * Discord has no threading (`supportsThreads = false`): chain segments
 * publish as separate, unrelated consecutive messages. See
 * DiscordProvider::post()'s docblock for what this means for
 * $replyToReleaseId specifically.
 *
 * DNS is faked for every test via SafeRemoteHost::fake() (same
 * convention as MastodonProviderTest) so none of this ever touches real
 * DNS/network: 'discord.com' is pinned to a public documentation-range
 * address; 'evil.internal' below is deliberately given a
 * private/metadata address for the host-validation tests.
 */

beforeEach(function () {
    SafeRemoteHost::fake(['discord.com' => ['198.51.100.20']]);
});

afterEach(function () {
    SafeRemoteHost::stopFaking();
});

/**
 * @return array<string, mixed>
 */
function discordFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/social/{$name}.json")), true);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function discordIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'discord',
        'token' => 'https://discord.com/api/webhooks/223704706495545344/7Vt0nyDNlksBSMK2E2ADv1UkV5hFCzRc7VOvV9G9DEIYQhCSTUhqZgFEUeoLkuC00qtR',
        'internal_id' => '223704706495545344',
        'settings' => ['channel_id' => '223704706495545344', 'guild_id' => '290926798626357999'],
    ], $overrides));
}

// --- registry ------------------------------------------------------------

it('resolves discord from the provider registry', function () {
    $provider = app(ProviderRegistry::class)->get('discord');

    expect($provider)->toBeInstanceOf(DiscordProvider::class)
        ->and($provider->identifier())->toBe('discord')
        ->and($provider->capabilities()->supportsThreads)->toBeFalse();
});

// --- generateAuthUrl() / authenticate() -----------------------------------

it('builds an authorize url with the webhook.incoming scope', function () {
    config(['services.discord.client_id' => 'discord-client-id']);

    $provider = app(ProviderRegistry::class)->get('discord');
    $authUrl = $provider->generateAuthUrl('csrf-state', 'https://app.test/callback');

    expect($authUrl->url)->toStartWith('https://discord.com/oauth2/authorize?')
        ->and($authUrl->url)->toContain('client_id=discord-client-id')
        ->and($authUrl->url)->toContain('scope=webhook.incoming')
        ->and($authUrl->url)->toContain('state=csrf-state');
});

it('completes authentication by exchanging the code for a channel webhook', function () {
    config(['services.discord.client_id' => 'discord-client-id', 'services.discord.client_secret' => 'discord-client-secret']);

    Http::fake([
        'discord.com/api/oauth2/token' => Http::response(discordFixture('discord_oauth_token')),
    ]);

    $provider = app(ProviderRegistry::class)->get('discord');
    $result = $provider->authenticate(['code' => 'auth-code'], 'https://app.test/callback');

    expect($result->internalId)->toBe('223704706495545344')
        ->and($result->name)->toBe('captain-hook')
        ->and($result->token)->toBe('https://discord.com/api/webhooks/223704706495545344/7Vt0nyDNlksBSMK2E2ADv1UkV5hFCzRc7VOvV9G9DEIYQhCSTUhqZgFEUeoLkuC00qtR')
        ->and($result->settings)->toBe(['channel_id' => '223704706495545344', 'guild_id' => '290926798626357999']);
});

it('maps an authentication rejection to an auth exception', function () {
    config(['services.discord.client_id' => 'discord-client-id', 'services.discord.client_secret' => 'discord-client-secret']);

    Http::fake([
        'discord.com/api/oauth2/token' => Http::response(discordFixture('discord_error_unauthorized'), 401),
    ]);

    $provider = app(ProviderRegistry::class)->get('discord');

    expect(fn () => $provider->authenticate(['code' => 'bad-code'], 'https://app.test/callback'))
        ->toThrow(ProviderAuthException::class);
});

it('rejects a webhook token exchange whose url does not point at discord.com', function () {
    config(['services.discord.client_id' => 'discord-client-id', 'services.discord.client_secret' => 'discord-client-secret']);

    $tampered = discordFixture('discord_oauth_token');
    $tampered['webhook']['url'] = 'https://evil.internal/steal-this';

    Http::fake([
        'discord.com/api/oauth2/token' => Http::response($tampered),
    ]);

    $provider = app(ProviderRegistry::class)->get('discord');

    expect(fn () => $provider->authenticate(['code' => 'auth-code'], 'https://app.test/callback'))
        ->toThrow(ProviderPermanentException::class);
});

// --- post(): happy path ----------------------------------------------------

it('publishes a message to the channel webhook and returns the release id', function () {
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(discordFixture('discord_execute_webhook')),
    ]);

    $integration = discordIntegration();
    $result = app(ProviderRegistry::class)->get('discord')->post($integration, [new PostPayloadDto('hello')]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->releaseId)->toBe('1099511627776000001');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/webhooks/')
        && $request['content'] === 'hello');
});

it('publishes a thread as separate consecutive messages, not linked replies', function () {
    Http::fakeSequence('discord.com/api/webhooks/*')
        ->push(discordFixture('discord_execute_webhook'))
        ->push(discordFixture('discord_execute_webhook_reply'));

    $integration = discordIntegration();
    $chain = [new PostPayloadDto('hello'), new PostPayloadDto('second segment')];
    $result = app(ProviderRegistry::class)->get('discord')->post($integration, $chain);

    expect($result)->toHaveCount(2)
        ->and($result[0]->releaseId)->toBe('1099511627776000001')
        ->and($result[1]->releaseId)->toBe('1099511627776000002');

    // Neither request carries any reply/message-reference field -- Discord
    // webhooks cannot express "reply to message", so consecutive segments
    // are just independent messages, in order.
    Http::assertSent(fn ($request) => $request['content'] === 'second segment'
        && ! isset($request['message_reference']));
});

// --- error mapping -----------------------------------------------------

it('rejects content over the discord character limit with a permanent exception', function () {
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(discordFixture('discord_error_bad_request'), 400),
    ]);

    $integration = discordIntegration();
    $tooLong = str_repeat('a', 2001);

    expect(fn () => app(ProviderRegistry::class)->get('discord')->post($integration, [new PostPayloadDto($tooLong)]))
        ->toThrow(ProviderPermanentException::class);
});

it('maps a discord 429 to a rate limit exception', function () {
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(discordFixture('discord_error_rate_limit'), 429),
    ]);

    $integration = discordIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('discord')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderRateLimitException::class);
});

it('maps a discord 401 (revoked webhook) to an auth exception', function () {
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(discordFixture('discord_error_unauthorized'), 401),
    ]);

    $integration = discordIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('discord')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderAuthException::class);
});

it('maps a discord 5xx to a transient exception', function () {
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(discordFixture('discord_error_server'), 503),
    ]);

    $integration = discordIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('discord')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderTransientException::class);
});

// --- replyToReleaseId: Discord cannot honour a thread anchor --------------

it('refuses to resume a chain onto a reply anchor instead of silently publishing a detached message', function () {
    Http::fake();

    $integration = discordIntegration();
    $resumedChain = [(new PostPayloadDto('second segment'))->withReplyToReleaseId('1099511627776000001')];

    expect(fn () => app(ProviderRegistry::class)->get('discord')->post($integration, $resumedChain))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNothingSent();
});

// --- partial failure -----------------------------------------------------

it('throws a partial failure exception carrying already-published segments when a later segment fails', function () {
    Http::fakeSequence('discord.com/api/webhooks/*')
        ->push(discordFixture('discord_execute_webhook'))
        ->push(discordFixture('discord_error_bad_request'), 400);

    $integration = discordIntegration();
    $chain = [new PostPayloadDto('first'), new PostPayloadDto('second'), new PostPayloadDto('third')];

    try {
        app(ProviderRegistry::class)->get('discord')->post($integration, $chain);
        test()->fail('Expected ProviderPartialFailureException.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveCount(1)
            ->and($e->published[0]->releaseId)->toBe('1099511627776000001');
    }
});

// --- webhook url host validation (it's a credential, revalidated per use) --

it('rejects publishing when the stored webhook url no longer points at discord.com', function () {
    Http::fake();

    $integration = discordIntegration(['token' => 'https://evil.internal/api/webhooks/1/abc']);

    expect(fn () => app(ProviderRegistry::class)->get('discord')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNothingSent();
});

// --- secret-leak protection ------------------------------------------------

it('does not leak the webhook token through the exception chain on a network-level failure', function () {
    $webhookUrl = 'https://discord.com/api/webhooks/223704706495545344/super-secret-webhook-token-abc123';

    Http::fake([
        'discord.com/*' => function () use ($webhookUrl) {
            throw new ConnectionException(
                "cURL error 6: Could not resolve host for {$webhookUrl}",
            );
        },
    ]);

    $integration = discordIntegration(['token' => $webhookUrl]);

    try {
        app(ProviderRegistry::class)->get('discord')->post($integration, [new PostPayloadDto('hello')]);
        test()->fail('Expected ProviderTransientException.');
    } catch (ProviderTransientException $e) {
        $messages = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        expect($messages)->not->toBeEmpty();

        foreach ($messages as $message) {
            expect($message)->not->toContain('super-secret-webhook-token-abc123');
        }
    }
});

// --- Block 3: markup delivery ---------------------------------------------

it('sends the canonical markup to discord untouched', function () {
    // Discord's own message syntax IS the canonical form for the four
    // marks it declares (**bold**, *italic*, ~~strike~~, `code`), so
    // there is nothing to translate and nothing to escape -- the body
    // must go out exactly as typed.
    Http::fake([
        'discord.com/api/webhooks/*' => Http::response(discordFixture('discord_execute_webhook')),
    ]);

    app(ProviderRegistry::class)->get('discord')
        ->post(discordIntegration(), [new PostPayloadDto('a **bold** claim & 5 < 6')]);

    Http::assertSent(fn ($request) => $request['content'] === 'a **bold** claim & 5 < 6');
});

it('does not offer a link mark, because discord would print the brackets', function () {
    $marks = app(ProviderRegistry::class)->get('discord')->capabilities()->supportedMarks;

    expect($marks)->not->toContain(TextMark::LINK)
        ->and($marks)->toContain(TextMark::BOLD);
});
