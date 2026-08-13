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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Task 14: standard OAuth2 against a user-supplied instance host, plus
 * the two-phase media upload (SupportsMedia) Mastodon's
 * `POST /api/v2/media` requires. See MastodonProvider's class docblock
 * for why generateAuthUrl() needed a `$params` channel this task added
 * to SocialProviderInterface.
 */

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
    Cache::put('mastodon_app_registration:mastodon.social', [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ], 60);

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
    Cache::put('mastodon_app_registration:mastodon.social', [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ], 60);

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
