<?php

use App\Models\Integration;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use App\Social\Providers\MastodonProvider;
use App\Support\SafeRemoteHost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/*
 * Task 14: standard OAuth2 against a user-supplied instance host, plus
 * the two-phase media upload (SupportsMedia) Mastodon's
 * `POST /api/v2/media` requires. See MastodonProvider's class docblock
 * for why generateAuthUrl() needed a `$params` channel this task added
 * to SocialProviderInterface.
 *
 * DNS is faked for every test via SafeRemoteHost::fake() (Task 14
 * review, Finding 1) so none of this ever touches real DNS/network:
 * 'mastodon.social' is pinned to a public documentation-range address
 * by default here; SSRF-specific tests below override the fake map with
 * a private/metadata address for the host they're exercising.
 */

beforeEach(function () {
    SafeRemoteHost::fake(['mastodon.social' => ['198.51.100.10']]);
});

afterEach(function () {
    SafeRemoteHost::stopFaking();
});

/**
 * @return array<string, mixed>
 */
function mastodonFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/social/{$name}.json")), true);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function mastodonIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'mastodon',
        'token' => 'mastodon-access-token',
        'internal_id' => '109876543210123456',
        'settings' => ['instance' => 'mastodon.social'],
    ], $overrides));
}

/**
 * Seeds a cached app registration the way MastodonProvider itself writes
 * one — ENCRYPTED (final-review batch 2, Important 7). Deliberately not a
 * plain `Cache::put([...])` of the raw array: this helper has to fail if
 * the provider ever goes back to storing that client_secret in Redis in
 * the clear, which is exactly what a plaintext fixture here would hide.
 */
function seedMastodonAppRegistration(string $host): void
{
    Cache::put(
        "mastodon_app_registration:{$host}",
        Crypt::encryptString((string) json_encode(['client_id' => 'client-id', 'client_secret' => 'client-secret'])),
        60,
    );
}

// --- registry --------------------------------------------------------------

it('resolves mastodon from the provider registry', function () {
    $provider = app(ProviderRegistry::class)->get('mastodon');

    expect($provider)->toBeInstanceOf(MastodonProvider::class)
        ->and($provider->identifier())->toBe('mastodon');
});

// --- generateAuthUrl() / authenticate() -------------------------------------

it('registers an app on the given instance and builds an authorize url', function () {
    Http::fake([
        'mastodon.social/api/v1/apps' => Http::response(mastodonFixture('mastodon_app')),
    ]);

    $provider = app(ProviderRegistry::class)->get('mastodon');
    $authUrl = $provider->generateAuthUrl('csrf-state', 'https://app.test/callback', ['instance' => 'mastodon.social']);

    expect($authUrl->url)->toStartWith('https://mastodon.social/oauth/authorize?')
        ->and($authUrl->url)->toContain('client_id=TWFzdG9kb25DbGllbnRJZEV4YW1wbGUxMjM0NTY3ODkw')
        ->and($authUrl->url)->toContain('state=csrf-state')
        ->and($authUrl->extra)->toBe(['instance' => 'mastodon.social']);
});

it('reuses a cached app registration instead of re-registering', function () {
    Http::fake([
        'mastodon.social/api/v1/apps' => Http::response(mastodonFixture('mastodon_app')),
    ]);

    $provider = app(ProviderRegistry::class)->get('mastodon');
    $provider->generateAuthUrl('state-1', 'https://app.test/callback', ['instance' => 'mastodon.social']);
    $provider->generateAuthUrl('state-2', 'https://app.test/callback', ['instance' => 'mastodon.social']);

    Http::assertSentCount(1);
});

it('rejects generateAuthUrl() with no instance host', function () {
    Http::fake();
    $provider = app(ProviderRegistry::class)->get('mastodon');

    expect(fn () => $provider->generateAuthUrl('state', 'https://app.test/callback'))
        ->toThrow(InvalidArgumentException::class);
});

it('completes authentication by exchanging the code and fetching the account', function () {
    seedMastodonAppRegistration('mastodon.social');

    Http::fake([
        'mastodon.social/oauth/token' => Http::response(mastodonFixture('mastodon_token')),
        'mastodon.social/api/v1/accounts/verify_credentials' => Http::response(mastodonFixture('mastodon_verify_credentials')),
    ]);

    $provider = app(ProviderRegistry::class)->get('mastodon');
    $result = $provider->authenticate(['instance' => 'mastodon.social', 'code' => 'auth-code'], 'https://app.test/callback');

    expect($result->internalId)->toBe('109876543210123456')
        ->and($result->name)->toBe('Scheduler Bot')
        ->and($result->token)->toBe('ZAgtwqUvErw8ZgAcffQhL3-XSRRRWEmVEqnwbtu8v2A')
        ->and($result->settings)->toBe(['instance' => 'mastodon.social']);
});

it('maps an authentication rejection to an auth exception', function () {
    seedMastodonAppRegistration('mastodon.social');

    Http::fake([
        'mastodon.social/oauth/token' => Http::response(mastodonFixture('mastodon_error_unauthorized'), 401),
    ]);

    $provider = app(ProviderRegistry::class)->get('mastodon');

    expect(fn () => $provider->authenticate(['instance' => 'mastodon.social', 'code' => 'bad-code'], 'https://app.test/callback'))
        ->toThrow(ProviderAuthException::class);
});

// --- uploadMedia() -----------------------------------------------------

it('uploads media and returns a MediaDto carrying the remote id', function () {
    Http::fake([
        'mastodon.social/api/v2/media' => Http::response(mastodonFixture('mastodon_media')),
    ]);

    $integration = mastodonIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png', altText: 'A screenshot');
    $provider = app(ProviderRegistry::class)->get('mastodon');
    expect($provider)->toBeInstanceOf(MastodonProvider::class);
    /** @var MastodonProvider $provider */
    $uploaded = $provider->uploadMedia($integration, $media);

    expect($uploaded->remoteId)->toBe('110432572085153422')
        ->and($uploaded->path)->toBe($media->path);
});

// --- post(): happy path --------------------------------------------------

it('publishes a status and returns the release id', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(mastodonFixture('mastodon_status')),
    ]);

    $integration = mastodonIntegration();
    $result = app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('hello')]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->releaseId)->toBe('110432573100000001')
        ->and($result[0]->releaseUrl)->toBe('https://mastodon.social/@scheduler_bot/110432573100000001');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/statuses')
        && $request['status'] === 'hello'
        && $request->hasHeader('Authorization', 'Bearer mastodon-access-token'));
});

it('publishes a thread, replying each segment to the previous status id', function () {
    Http::fakeSequence('mastodon.social/api/v1/statuses')
        ->push(mastodonFixture('mastodon_status'))
        ->push(mastodonFixture('mastodon_status_reply'));

    $integration = mastodonIntegration();
    $chain = [new PostPayloadDto('hello'), new PostPayloadDto('second segment')];
    $result = app(ProviderRegistry::class)->get('mastodon')->post($integration, $chain);

    expect($result)->toHaveCount(2)
        ->and($result[0]->releaseId)->toBe('110432573100000001')
        ->and($result[1]->releaseId)->toBe('110432573100000002');

    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), '/api/v1/statuses') && ! isset($request['in_reply_to_id']),
        fn ($request) => str_contains($request->url(), '/api/v1/statuses') && $request['in_reply_to_id'] === '110432573100000001',
    ]);
});

it('publishes a status referencing pre-uploaded media ids', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(mastodonFixture('mastodon_status')),
    ]);

    $integration = mastodonIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png', remoteId: 'already-uploaded-id');
    app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('with media', [$media])]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/statuses')
        && $request['media_ids'][0] === 'already-uploaded-id');
});

it('rejects publishing media that was never pre-uploaded', function () {
    Http::fake();

    $integration = mastodonIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png');

    expect(fn () => app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('with media', [$media])]))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNothingSent();
});

// --- error mapping -----------------------------------------------------

it('rejects content over the mastodon character limit with a permanent exception', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(mastodonFixture('mastodon_error_unprocessable'), 422),
    ]);

    $integration = mastodonIntegration();
    $tooLong = str_repeat('a', 501);

    expect(fn () => app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto($tooLong)]))
        ->toThrow(ProviderPermanentException::class);
});

it('maps a mastodon 429 with Retry-After to a rate limit exception', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(mastodonFixture('mastodon_error_rate_limit'), 429, ['Retry-After' => '60']),
    ]);

    $integration = mastodonIntegration();

    try {
        app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('hello')]);
        test()->fail('Expected ProviderRateLimitException.');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(60);
    }
});

it('maps a mastodon 401 to an auth exception', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(mastodonFixture('mastodon_error_unauthorized'), 401),
    ]);

    $integration = mastodonIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderAuthException::class);
});

it('maps a mastodon 5xx to a transient exception', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(['error' => 'Internal server error'], 503),
    ]);

    $integration = mastodonIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderTransientException::class);
});

// --- thread resumption + partial failure --------------------------------

it('attaches a resumed chain to the given reply-to anchor instead of starting a new root', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(mastodonFixture('mastodon_status_reply')),
    ]);

    $integration = mastodonIntegration();
    $resumedChain = [(new PostPayloadDto('second segment'))->withReplyToReleaseId('110432573100000001')];
    $result = app(ProviderRegistry::class)->get('mastodon')->post($integration, $resumedChain);

    expect($result[0]->releaseId)->toBe('110432573100000002');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/statuses')
        && $request['in_reply_to_id'] === '110432573100000001');
});

it('throws a partial failure exception carrying already-published segments when a later segment fails', function () {
    Http::fakeSequence('mastodon.social/api/v1/statuses')
        ->push(mastodonFixture('mastodon_status'))
        ->push(mastodonFixture('mastodon_error_unprocessable'), 422);

    $integration = mastodonIntegration();
    $chain = [new PostPayloadDto('first'), new PostPayloadDto('second'), new PostPayloadDto('third')];

    try {
        app(ProviderRegistry::class)->get('mastodon')->post($integration, $chain);
        test()->fail('Expected ProviderPartialFailureException.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveCount(1)
            ->and($e->published[0]->releaseId)->toBe('110432573100000001');
    }
});

it('does not throw a partial failure exception when the very first segment fails', function () {
    Http::fake([
        'mastodon.social/api/v1/statuses' => Http::response(mastodonFixture('mastodon_error_unprocessable'), 422),
    ]);

    $integration = mastodonIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('first')]))
        ->toThrow(ProviderPermanentException::class);
});

// --- Task 14 review, Finding 1 (CRITICAL): the instance host is a
// server-side-request target an org admin controls end to end, so it
// must be checked against App\Support\SafeRemoteHost both when first
// accepted (generateAuthUrl/authenticate) AND every time a previously-
// accepted, now-stored host is reused (post/uploadMedia) -- DNS answers
// change, so a host safe at connect time can resolve privately later.

it('rejects generateAuthUrl() when the instance host resolves to a private/metadata address', function () {
    SafeRemoteHost::fake(['evil.internal' => ['169.254.169.254']]);
    Http::fake();

    $provider = app(ProviderRegistry::class)->get('mastodon');

    expect(fn () => $provider->generateAuthUrl('state', 'https://app.test/callback', ['instance' => 'evil.internal']))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects authenticate() when the instance host resolves to a private/metadata address', function () {
    SafeRemoteHost::fake(['evil.internal' => ['127.0.0.1']]);
    Http::fake();

    $provider = app(ProviderRegistry::class)->get('mastodon');

    expect(fn () => $provider->authenticate(['instance' => 'evil.internal', 'code' => 'x'], 'https://app.test/callback'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects publishing when a stored channel\'s instance host now resolves to a private address (DNS rebinding)', function () {
    // The channel connected fine (its host resolved publicly at connect
    // time -- see the file-level beforeEach), but by the time a
    // scheduled publish runs, the same hostname now resolves to the
    // cloud-metadata address. hostFor() must catch this on every use,
    // not just once at connect time.
    SafeRemoteHost::fake(['mastodon.social' => ['169.254.169.254']]);
    Http::fake();

    $integration = mastodonIntegration(); // settings.instance = 'mastodon.social'

    expect(fn () => app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNothingSent();
});

it('rejects uploadMedia() when a stored channel\'s instance host now resolves to a private address', function () {
    SafeRemoteHost::fake(['mastodon.social' => ['10.0.0.5']]);
    Http::fake();

    $integration = mastodonIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png');

    $provider = app(ProviderRegistry::class)->get('mastodon');
    expect($provider)->toBeInstanceOf(MastodonProvider::class);
    /** @var MastodonProvider $provider */
    expect(fn () => $provider->uploadMedia($integration, $media))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNothingSent();
});

it('propagates the underlying reason through UnsafeRemoteHostException for a rejected instance', function () {
    SafeRemoteHost::fake(['evil.internal' => ['169.254.169.254']]);
    Http::fake();

    $provider = app(ProviderRegistry::class)->get('mastodon');

    try {
        $provider->generateAuthUrl('state', 'https://app.test/callback', ['instance' => 'evil.internal']);
        test()->fail('Expected InvalidArgumentException.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('169.254.169.254');
    }
});

// --- Task 14 review, Finding 2: verified for Mastodon too, even though
// its credentials travel via the Authorization header rather than the
// URL -- the policy is "never chain a raw transport exception", not
// "only when a secret is known to be in the URL today".

it('does not leak the access token through the exception chain on a network-level failure', function () {
    $token = 'super-secret-mastodon-token-abc123';

    Http::fake([
        'mastodon.social/api/v1/statuses' => function () use ($token) {
            throw new ConnectionException(
                "cURL error 6: Could not resolve host for https://mastodon.social/api/v1/statuses (Authorization: Bearer {$token})",
            );
        },
    ]);

    $integration = mastodonIntegration(['token' => $token]);

    try {
        app(ProviderRegistry::class)->get('mastodon')->post($integration, [new PostPayloadDto('hello')]);
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

/*
 * Final-review batch 2, Important 7: the per-instance app registration
 * holds a real OAuth client_secret for that server, and it lived in Redis
 * in plaintext for 30 days — the only credential in this application
 * stored without encryption, while every `integrations` column gets the
 * `encrypted` cast. Redis is not encrypted at rest in this deployment.
 */
it('never writes the cached mastodon client_secret to the cache store in plaintext', function () {
    Http::fake([
        'mastodon.social/api/v1/apps' => Http::response(mastodonFixture('mastodon_app')),
    ]);

    Cache::forget('mastodon_app_registration:mastodon.social');

    app(ProviderRegistry::class)->get('mastodon')
        ->generateAuthUrl('state', 'https://app.test/callback', ['instance' => 'mastodon.social']);

    $raw = Cache::get('mastodon_app_registration:mastodon.social');
    $secret = mastodonFixture('mastodon_app')['client_secret'];

    expect($raw)->toBeString()
        ->and($raw)->not->toContain($secret)
        ->and($raw)->not->toContain('client_secret')
        // ...and it is still usable: encrypted-at-rest must not mean
        // "the connect flow silently stops working".
        ->and(json_decode(Crypt::decryptString($raw), true)['client_secret'])->toBe($secret);
});
