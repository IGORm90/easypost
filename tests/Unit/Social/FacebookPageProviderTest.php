<?php

use App\Models\Integration;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\DeclaresUpdatableSettings;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SupportsOptions;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Exceptions\UnknownProviderOptionException;
use App\Social\ProviderRegistry;
use App\Social\Providers\FacebookPageProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Facebook Page adapter. OAuth against one operator-registered Meta app
 * (config('services.facebook_page')) -- NOT ManualTokenAuth, NOT
 * DeclaresAuthFields, so `GET /api/providers` reports
 * `auth_type: 'oauth'` with an empty `auth_fields`, which is what lets
 * the SPA's fast-connect branch skip the modal entirely.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.facebook_page.client_id' => 'fb-client-id',
        'services.facebook_page.client_secret' => 'fb-client-secret',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function facebookPageIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'facebook-page',
        'token' => 'fb-long-lived-token',
        'internal_id' => '10000000001',
        'name' => 'Jane Doe',
        'settings' => ['page_id' => 'page-1'],
    ], $overrides));
}

function facebookPageProvider(): FacebookPageProvider
{
    $provider = app(ProviderRegistry::class)->get('facebook-page');

    assert($provider instanceof FacebookPageProvider);

    return $provider;
}

// --- registry / contracts ------------------------------------------------

it('resolves facebook-page from the provider registry as an oauth-only provider', function () {
    $provider = facebookPageProvider();

    expect($provider)->toBeInstanceOf(FacebookPageProvider::class)
        ->and($provider->identifier())->toBe('facebook-page')
        ->and($provider->name())->toBe('Facebook Page')
        ->and($provider)->not->toBeInstanceOf(ManualTokenAuth::class)
        ->and($provider)->not->toBeInstanceOf(DeclaresAuthFields::class)
        ->and($provider)->toBeInstanceOf(SupportsRefresh::class)
        ->and($provider)->toBeInstanceOf(SupportsOptions::class)
        ->and($provider)->toBeInstanceOf(DeclaresUpdatableSettings::class);
});

// --- generateAuthUrl() -----------------------------------------------------

it('builds an authorize url carrying the state, PKCE challenge and scopes, and returns the verifier in extra', function () {
    $url = facebookPageProvider()->generateAuthUrl('opaque-state-value', 'https://app.test/integrations/callback');

    parse_str((string) parse_url($url->url, PHP_URL_QUERY), $query);

    expect($url->url)->toStartWith('https://www.facebook.com/v21.0/dialog/oauth?')
        ->and($query['state'])->toBe('opaque-state-value')
        ->and($query['redirect_uri'])->toBe('https://app.test/integrations/callback')
        ->and($query['client_id'])->toBe('fb-client-id')
        ->and($query['scope'])->toContain('pages_show_list')->toContain('pages_manage_posts')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->not->toBeEmpty()
        ->and($url->extra['code_verifier'])->toBeString()
        ->and($url->extra['code_verifier'])->not->toBeEmpty();
});

it('fails permanently when the client id is not configured', function () {
    config(['services.facebook_page.client_id' => null]);

    facebookPageProvider()->generateAuthUrl('state', 'https://app.test/callback');
})->throws(ProviderPermanentException::class);

// --- authenticate() ---------------------------------------------------------

it('exchanges the code, immediately extends to a long-lived token, and fetches the profile', function () {
    Http::fake([
        'graph.facebook.com/v21.0/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'short-lived-token', 'expires_in' => 3600])
            ->push(['access_token' => 'long-lived-token', 'expires_in' => 5_184_000]),
        'graph.facebook.com/v21.0/me*' => Http::response(['id' => '10000000001', 'name' => 'Jane Doe']),
    ]);

    $result = facebookPageProvider()->authenticate(
        ['code' => 'auth-code-123', 'code_verifier' => 'verifier-abc'],
        'https://app.test/integrations/callback',
    );

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'oauth/access_token') || ($request['code'] ?? null) === null) {
            return true;
        }

        return $request['code'] === 'auth-code-123' && $request['code_verifier'] === 'verifier-abc';
    });

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'oauth/access_token')
        || ($request['grant_type'] ?? null) !== 'fb_exchange_token'
        || $request['fb_exchange_token'] === 'short-lived-token');

    expect($result->internalId)->toBe('10000000001')
        ->and($result->name)->toBe('Jane Doe')
        ->and($result->token)->toBe('long-lived-token')
        ->and($result->expiresAt?->diffInSeconds(now()->addSeconds(5_184_000)))->toBeLessThan(2);
});

it('rejects authenticate() with no code before any network call', function () {
    facebookPageProvider()->authenticate([], 'https://app.test/callback');
})->throws(ProviderAuthException::class);

// --- refresh() ---------------------------------------------------------------

it('refreshes by re-exchanging the current long-lived token', function () {
    Http::fake([
        'graph.facebook.com/v21.0/oauth/access_token*' => Http::response(['access_token' => 'rotated-token', 'expires_in' => 5_184_000]),
    ]);

    $result = facebookPageProvider()->refresh(facebookPageIntegration());

    Http::assertSent(fn ($request) => $request['grant_type'] === 'fb_exchange_token' && $request['fb_exchange_token'] === 'fb-long-lived-token');

    expect($result->token)->toBe('rotated-token');
});

it('throws a permanent auth exception when refresh has no stored token', function () {
    facebookPageProvider()->refresh(facebookPageIntegration(['token' => '']));
})->throws(ProviderAuthException::class);

it('maps a rejected refresh to an auth exception via the subcode', function () {
    Http::fake(['graph.facebook.com/v21.0/oauth/access_token*' => Http::response([
        'error' => ['message' => 'Session has expired', 'code' => 190, 'error_subcode' => 463],
    ], 400)]);

    facebookPageProvider()->refresh(facebookPageIntegration());
})->throws(ProviderAuthException::class);

// --- options() / settings ----------------------------------------------------

it('lists connected pages from /me/accounts', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response([
        'data' => [
            ['id' => 'page-1', 'name' => 'My Page'],
            ['id' => 'page-2', 'name' => 'Other Page'],
        ],
    ])]);

    $options = facebookPageProvider()->options(facebookPageIntegration(), 'pages');

    expect($options)->toBe([
        ['value' => 'page-1', 'label' => 'My Page'],
        ['value' => 'page-2', 'label' => 'Other Page'],
    ]);
});

it('throws for an unknown option name', function () {
    facebookPageProvider()->options(facebookPageIntegration(), 'boards');
})->throws(UnknownProviderOptionException::class);

it('declares page_id as the only updatable setting, mapped to the pages option', function () {
    $provider = facebookPageProvider();

    expect($provider->updatableSettings())->toHaveKey('page_id')
        ->and($provider->settingOptions())->toBe(['page_id' => 'pages']);
});

// --- post() -------------------------------------------------------------------

it('publishes a text post to the feed using the resolved page token, not the user token', function () {
    Http::fake([
        'graph.facebook.com/v21.0/me/accounts*' => Http::response(['data' => [
            ['id' => 'page-1', 'access_token' => 'page-token-1'],
        ]]),
        'graph.facebook.com/v21.0/page-1/feed*' => Http::response(['id' => 'page-1_123']),
    ]);

    $result = facebookPageProvider()->post(facebookPageIntegration(), [
        new PostPayloadDto(content: 'hello world'),
    ]);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/feed')
        || ($request['message'] === 'hello world' && $request['access_token'] === 'page-token-1'));

    expect($result[0]->releaseId)->toBe('page-1_123');
});

it('publishes a photo post when the segment carries one image', function () {
    Http::fake([
        'graph.facebook.com/v21.0/me/accounts*' => Http::response(['data' => [
            ['id' => 'page-1', 'access_token' => 'page-token-1'],
        ]]),
        'graph.facebook.com/v21.0/page-1/photos*' => Http::response(['id' => 'photo-1', 'post_id' => 'page-1_456']),
    ]);

    $media = new MediaDto(path: __FILE__, mimeType: 'image/png', originalFilename: 'photo.png');

    $result = facebookPageProvider()->post(facebookPageIntegration(), [
        new PostPayloadDto(content: 'a caption', media: [$media]),
    ]);

    expect($result[0]->releaseId)->toBe('page-1_456');
});

it('fails permanently when the channel has no page selected', function () {
    facebookPageProvider()->post(facebookPageIntegration(['settings' => []]), [
        new PostPayloadDto(content: 'hello'),
    ]);
})->throws(ProviderPermanentException::class);

it('fails permanently when the selected page is no longer in /me/accounts', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response(['data' => []])]);

    facebookPageProvider()->post(facebookPageIntegration(), [
        new PostPayloadDto(content: 'hello'),
    ]);
})->throws(ProviderPermanentException::class);

it('rejects a resumed-chain anchor since this network never threads', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response(['data' => [
        ['id' => 'page-1', 'access_token' => 'page-token-1'],
    ]])]);

    facebookPageProvider()->post(facebookPageIntegration(), [
        (new PostPayloadDto(content: 'hello'))->withReplyToReleaseId('page-1_999'),
    ]);
})->throws(ProviderPermanentException::class);

// --- error mapping ------------------------------------------------------------

it('maps a rate-limit error code to ProviderRateLimitException', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response([
        'error' => ['message' => 'Application request limit reached', 'code' => 4],
    ], 400)]);

    facebookPageProvider()->options(facebookPageIntegration(), 'pages');
})->throws(ProviderRateLimitException::class);

it('maps an ordinary 5xx to ProviderTransientException', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response('upstream error', 503)]);

    facebookPageProvider()->options(facebookPageIntegration(), 'pages');
})->throws(ProviderTransientException::class);

it('does not leak the access token through the exception chain on a network-level failure', function () {
    Http::fake(fn () => throw new ConnectionException('connection refused, token=fb-long-lived-token'));

    try {
        facebookPageProvider()->options(facebookPageIntegration(), 'pages');
        test()->fail('Expected a transient exception.');
    } catch (ProviderTransientException $e) {
        expect($e->getMessage())->not->toContain('fb-long-lived-token')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('refuses a redirect from facebook instead of following it', function () {
    Http::fake(['graph.facebook.com/v21.0/me/accounts*' => Http::response('SECRET-BODY-NEVER-SHOWN', 302, [
        'Location' => 'http://169.254.169.254/latest/meta-data',
    ])]);

    try {
        facebookPageProvider()->options(facebookPageIntegration(), 'pages');
        test()->fail('Expected a permanent exception.');
    } catch (ProviderPermanentException $e) {
        expect($e->getMessage())->not->toContain('169.254.169.254')
            ->and($e->getMessage())->not->toContain('SECRET-BODY-NEVER-SHOWN');
    }

    Http::assertSentCount(1);
});

// --- capabilities --------------------------------------------------------------

it('declares capabilities with no default left unstated', function () {
    $capabilities = facebookPageProvider()->capabilities();

    expect($capabilities->supportsThreads)->toBeFalse()
        ->and($capabilities->maxThreadLength)->toBe(1)
        ->and($capabilities->requiresMedia)->toBeFalse()
        ->and($capabilities->supportedMarks)->toBe([]);
});
