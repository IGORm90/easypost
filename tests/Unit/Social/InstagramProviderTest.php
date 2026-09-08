<?php

use App\Models\Integration;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\DeclaresUpdatableSettings;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SupportsMedia;
use App\Social\Contracts\SupportsOptions;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use App\Social\Providers\InstagramProvider;
use Illuminate\Support\Facades\Http;

/*
 * Instagram (Business) adapter. OAuth against one operator-registered
 * Meta app (config('services.instagram')) -- NOT ManualTokenAuth, NOT
 * DeclaresAuthFields, so `GET /api/providers` reports
 * `auth_type: 'oauth'` with an empty `auth_fields`. Publishing is always
 * a two-step Graph container flow driven off MediaDto::$publicUrl --
 * this provider never uploads bytes, so it deliberately does NOT
 * implement SupportsMedia.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.instagram.client_id' => 'ig-client-id',
        'services.instagram.client_secret' => 'ig-client-secret',
        // A real "never finishes" poll takes 10 x 3s in production; a
        // test asserting that outcome should not actually sleep 27s.
        'services.meta.container_poll_max_attempts' => 2,
        'services.meta.container_poll_delay_seconds' => 0,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function instagramIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'instagram',
        'token' => 'ig-long-lived-token',
        'internal_id' => '10000000001',
        'name' => 'Jane Doe',
        'settings' => ['ig_user_id' => 'ig-account-1'],
    ], $overrides));
}

function instagramProvider(): InstagramProvider
{
    $provider = app(ProviderRegistry::class)->get('instagram');

    assert($provider instanceof InstagramProvider);

    return $provider;
}

// --- registry / contracts ------------------------------------------------

it('resolves instagram from the provider registry as an oauth-only provider', function () {
    $provider = instagramProvider();

    expect($provider)->toBeInstanceOf(InstagramProvider::class)
        ->and($provider->identifier())->toBe('instagram')
        ->and($provider)->not->toBeInstanceOf(ManualTokenAuth::class)
        ->and($provider)->not->toBeInstanceOf(DeclaresAuthFields::class)
        ->and($provider)->not->toBeInstanceOf(SupportsMedia::class)
        ->and($provider)->toBeInstanceOf(SupportsRefresh::class)
        ->and($provider)->toBeInstanceOf(SupportsOptions::class)
        ->and($provider)->toBeInstanceOf(DeclaresUpdatableSettings::class);
});

it('requires media, unlike the other Meta providers', function () {
    expect(instagramProvider()->capabilities()->requiresMedia)->toBeTrue();
});

// --- generateAuthUrl() / authenticate() -------------------------------------

it('builds an authorize url requesting instagram scopes alongside pages_show_list', function () {
    $url = instagramProvider()->generateAuthUrl('state-1', 'https://app.test/integrations/callback');

    parse_str((string) parse_url($url->url, PHP_URL_QUERY), $query);

    expect($query['scope'])->toContain('instagram_basic')->toContain('instagram_content_publish')->toContain('pages_show_list')
        ->and($url->extra['code_verifier'])->toBeString()
        ->and($url->extra['code_verifier'])->not->toBeEmpty();
});

it('exchanges the code, extends the token, and fetches the profile', function () {
    Http::fake([
        'graph.facebook.com/v21.0/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'short-lived', 'expires_in' => 3600])
            ->push(['access_token' => 'long-lived', 'expires_in' => 5_184_000]),
        'graph.facebook.com/v21.0/me*' => Http::response(['id' => '10000000001', 'name' => 'Jane Doe']),
    ]);

    $result = instagramProvider()->authenticate(['code' => 'code-1', 'code_verifier' => 'v1'], 'https://app.test/callback');

    expect($result->internalId)->toBe('10000000001')
        ->and($result->token)->toBe('long-lived');
});

it('rejects authenticate() with no code before any network call', function () {
    instagramProvider()->authenticate([], 'https://app.test/callback');
})->throws(ProviderAuthException::class);

// --- refresh() ---------------------------------------------------------------

it('refreshes by re-exchanging the current long-lived token', function () {
    Http::fake(['graph.facebook.com/v21.0/oauth/access_token*' => Http::response(['access_token' => 'rotated', 'expires_in' => 5_184_000])]);

    $result = instagramProvider()->refresh(instagramIntegration());

    Http::assertSent(fn ($request) => $request['grant_type'] === 'fb_exchange_token' && $request['fb_exchange_token'] === 'ig-long-lived-token');
    expect($result->token)->toBe('rotated');
});

it('throws a permanent auth exception when refresh has no stored token', function () {
    instagramProvider()->refresh(instagramIntegration(['token' => '']));
})->throws(ProviderAuthException::class);

// --- options() / settings ----------------------------------------------------

it('lists only pages that have a linked instagram business account', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response(['data' => [
        ['name' => 'Page With IG', 'instagram_business_account' => ['id' => 'ig-account-1', 'username' => 'brand']],
        ['name' => 'Page Without IG'],
    ]])]);

    $options = instagramProvider()->options(instagramIntegration(), 'accounts');

    expect($options)->toBe([['value' => 'ig-account-1', 'label' => '@brand']]);
});

it('declares ig_user_id as the only updatable setting, mapped to the accounts option', function () {
    $provider = instagramProvider();

    expect($provider->updatableSettings())->toHaveKey('ig_user_id')
        ->and($provider->settingOptions())->toBe(['ig_user_id' => 'accounts']);
});

// --- post() -------------------------------------------------------------------

it('fails validation-side before any request when the channel has no account selected', function () {
    instagramProvider()->post(instagramIntegration(['settings' => []]), [
        new PostPayloadDto(content: 'caption', media: [new MediaDto(path: '/tmp/x.png', mimeType: 'image/png', publicUrl: 'https://cdn.test/x.png')]),
    ]);
})->throws(ProviderPermanentException::class);

it('refuses to publish without any media even though a channel is selected', function () {
    instagramProvider()->post(instagramIntegration(), [
        new PostPayloadDto(content: 'no media here'),
    ]);
})->throws(ProviderPermanentException::class);

it('fails permanently when a media item has no public url', function () {
    instagramProvider()->post(instagramIntegration(), [
        new PostPayloadDto(content: 'caption', media: [new MediaDto(path: '/tmp/x.png', mimeType: 'image/png')]),
    ]);
})->throws(ProviderPermanentException::class);

it('publishes a single image as one container, polls it, then publishes it', function () {
    Http::fake([
        'graph.facebook.com/v21.0/ig-account-1/media' => Http::response(['id' => 'container-1']),
        'graph.facebook.com/v21.0/container-1?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.facebook.com/v21.0/ig-account-1/media_publish' => Http::response(['id' => 'ig-post-1']),
    ]);

    $media = new MediaDto(path: '/tmp/x.png', mimeType: 'image/png', publicUrl: 'https://cdn.test/x.png');

    $result = instagramProvider()->post(instagramIntegration(), [
        new PostPayloadDto(content: 'a caption', media: [$media]),
    ]);

    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/ig-account-1/media')
        || ($request['image_url'] === 'https://cdn.test/x.png' && $request['caption'] === 'a caption'));

    expect($result[0]->releaseId)->toBe('ig-post-1');
});

it('creates per-item carousel children then one carousel container for more than one image', function () {
    Http::fake([
        'graph.facebook.com/v21.0/ig-account-1/media' => Http::sequence()
            ->push(['id' => 'child-1'])
            ->push(['id' => 'child-2'])
            ->push(['id' => 'carousel-1']),
        'graph.facebook.com/v21.0/carousel-1?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.facebook.com/v21.0/ig-account-1/media_publish' => Http::response(['id' => 'ig-post-2']),
    ]);

    $media = [
        new MediaDto(path: '/tmp/a.png', mimeType: 'image/png', publicUrl: 'https://cdn.test/a.png'),
        new MediaDto(path: '/tmp/b.png', mimeType: 'image/png', publicUrl: 'https://cdn.test/b.png'),
    ];

    $result = instagramProvider()->post(instagramIntegration(), [
        new PostPayloadDto(content: 'carousel caption', media: $media),
    ]);

    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/media_publish')
        || $request['creation_id'] === 'carousel-1');

    expect($result[0]->releaseId)->toBe('ig-post-2');
});

it('retries as transient when a video container never finishes processing', function () {
    Http::fake([
        'graph.facebook.com/v21.0/ig-account-1/media' => Http::response(['id' => 'container-vid']),
        'graph.facebook.com/v21.0/container-vid?*' => Http::response(['status_code' => 'IN_PROGRESS']),
    ]);

    $media = new MediaDto(path: '/tmp/v.mp4', mimeType: 'video/mp4', publicUrl: 'https://cdn.test/v.mp4');

    instagramProvider()->post(instagramIntegration(), [
        new PostPayloadDto(content: 'a video', media: [$media]),
    ]);
})->throws(ProviderTransientException::class);

it('rejects a resumed-chain anchor since this network never threads', function () {
    $media = new MediaDto(path: '/tmp/x.png', mimeType: 'image/png', publicUrl: 'https://cdn.test/x.png');

    instagramProvider()->post(instagramIntegration(), [
        (new PostPayloadDto(content: 'hello', media: [$media]))->withReplyToReleaseId('ig-post-999'),
    ]);
})->throws(ProviderPermanentException::class);

it('refuses a redirect from graph instead of following it', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response('SECRET', 302, ['Location' => 'http://169.254.169.254/'])]);

    try {
        instagramProvider()->options(instagramIntegration(), 'accounts');
        test()->fail('Expected a permanent exception.');
    } catch (ProviderPermanentException $e) {
        expect($e->getMessage())->not->toContain('169.254.169.254')
            ->and($e->getMessage())->not->toContain('SECRET');
    }

    Http::assertSentCount(1);
});
