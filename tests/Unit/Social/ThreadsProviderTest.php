<?php

use App\Models\Integration;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\ProviderRegistry;
use App\Social\Providers\ThreadsProvider;
use Illuminate\Support\Facades\Http;

/*
 * Threads adapter. OAuth against one operator-registered Meta app
 * (config('services.meta_threads')) -- NOT ManualTokenAuth, NOT
 * DeclaresAuthFields, so `GET /api/providers` reports
 * `auth_type: 'oauth'` with an empty `auth_fields`. Unlike the other two
 * Meta providers, Threads models a native reply chain --
 * supportsThreads: true.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.meta_threads.client_id' => 'threads-client-id',
        'services.meta_threads.client_secret' => 'threads-client-secret',
        'services.meta.container_poll_max_attempts' => 2,
        'services.meta.container_poll_delay_seconds' => 0,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function threadsIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'meta-threads',
        'token' => 'threads-long-lived-token',
        'internal_id' => 'threads-user-1',
        'name' => '@brand',
        'settings' => [],
    ], $overrides));
}

function threadsProvider(): ThreadsProvider
{
    $provider = app(ProviderRegistry::class)->get('meta-threads');

    assert($provider instanceof ThreadsProvider);

    return $provider;
}

// --- registry / contracts ------------------------------------------------

it('resolves meta-threads from the provider registry as an oauth-only provider', function () {
    $provider = threadsProvider();

    expect($provider)->toBeInstanceOf(ThreadsProvider::class)
        ->and($provider->identifier())->toBe('meta-threads')
        ->and($provider->name())->toBe('Threads')
        ->and($provider)->not->toBeInstanceOf(ManualTokenAuth::class)
        ->and($provider)->not->toBeInstanceOf(DeclaresAuthFields::class)
        ->and($provider)->toBeInstanceOf(SupportsRefresh::class)
        ->and($provider->capabilities()->supportsThreads)->toBeTrue()
        ->and($provider->capabilities()->requiresMedia)->toBeFalse();
});

// --- generateAuthUrl() / authenticate() -------------------------------------

it('builds an authorize url on threads.net with threads scopes', function () {
    $url = threadsProvider()->generateAuthUrl('state-1', 'https://app.test/integrations/callback');

    parse_str((string) parse_url($url->url, PHP_URL_QUERY), $query);

    expect($url->url)->toStartWith('https://threads.net/oauth/authorize?')
        ->and($query['scope'])->toContain('threads_basic')->toContain('threads_content_publish')
        ->and($url->extra['code_verifier'])->toBeString()
        ->and($url->extra['code_verifier'])->not->toBeEmpty();
});

it('exchanges the code on graph.threads.net, extends the token, and fetches the profile', function () {
    Http::fake([
        'graph.threads.net/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 'threads-user-1']),
        'graph.threads.net/access_token*' => Http::response(['access_token' => 'long-lived', 'expires_in' => 5_184_000]),
        'graph.threads.net/v1.0/me*' => Http::response(['id' => 'threads-user-1', 'username' => 'brand']),
    ]);

    $result = threadsProvider()->authenticate(['code' => 'code-1', 'code_verifier' => 'v1'], 'https://app.test/callback');

    Http::assertSent(fn ($request) => $request->url() !== 'https://graph.threads.net/oauth/access_token'
        || ($request['code'] === 'code-1' && $request['code_verifier'] === 'v1'));

    expect($result->internalId)->toBe('threads-user-1')
        ->and($result->name)->toBe('@brand')
        ->and($result->token)->toBe('long-lived');
});

it('rejects authenticate() with no code before any network call', function () {
    threadsProvider()->authenticate([], 'https://app.test/callback');
})->throws(ProviderAuthException::class);

// --- refresh() ---------------------------------------------------------------

it('refreshes via th_exchange_token authenticated with the client secret alone', function () {
    Http::fake(['graph.threads.net/access_token*' => Http::response(['access_token' => 'rotated', 'expires_in' => 5_184_000])]);

    $result = threadsProvider()->refresh(threadsIntegration());

    Http::assertSent(fn ($request) => $request['grant_type'] === 'th_exchange_token'
        && $request['access_token'] === 'threads-long-lived-token'
        && $request['client_secret'] === 'threads-client-secret');

    expect($result->token)->toBe('rotated');
});

it('throws a permanent auth exception when refresh has no stored token', function () {
    threadsProvider()->refresh(threadsIntegration(['token' => '']));
})->throws(ProviderAuthException::class);

// --- post() / single segment ---------------------------------------------------

it('publishes a text-only segment as a TEXT container', function () {
    Http::fake([
        'graph.threads.net/v1.0/threads-user-1/threads' => Http::response(['id' => 'container-1']),
        'graph.threads.net/v1.0/container-1?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.threads.net/v1.0/threads-user-1/threads_publish' => Http::response(['id' => 'thread-1']),
    ]);

    $result = threadsProvider()->post(threadsIntegration(), [new PostPayloadDto(content: 'hello threads')]);

    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/threads-user-1/threads')
        || ($request['media_type'] === 'TEXT' && $request['text'] === 'hello threads'));

    expect($result[0]->releaseId)->toBe('thread-1');
});

it('publishes an image segment as an IMAGE container built from the public url', function () {
    Http::fake([
        'graph.threads.net/v1.0/threads-user-1/threads' => Http::response(['id' => 'container-2']),
        'graph.threads.net/v1.0/container-2?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.threads.net/v1.0/threads-user-1/threads_publish' => Http::response(['id' => 'thread-2']),
    ]);

    $media = new MediaDto(path: '/tmp/x.png', mimeType: 'image/png', publicUrl: 'https://cdn.test/x.png');

    threadsProvider()->post(threadsIntegration(), [new PostPayloadDto(content: 'with a picture', media: [$media])]);

    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/threads-user-1/threads')
        || ($request['media_type'] === 'IMAGE' && $request['image_url'] === 'https://cdn.test/x.png'));
});

it('fails permanently when an image segment has no public url', function () {
    threadsProvider()->post(threadsIntegration(), [
        new PostPayloadDto(content: 'no url', media: [new MediaDto(path: '/tmp/x.png', mimeType: 'image/png')]),
    ]);
})->throws(ProviderPermanentException::class);

// --- post() / threading ---------------------------------------------------------

it('replies each later segment to the previous one it just published', function () {
    Http::fake([
        'graph.threads.net/v1.0/threads-user-1/threads' => Http::sequence()
            ->push(['id' => 'container-root'])
            ->push(['id' => 'container-reply']),
        'graph.threads.net/v1.0/container-root?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.threads.net/v1.0/container-reply?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.threads.net/v1.0/threads-user-1/threads_publish' => Http::sequence()
            ->push(['id' => 'thread-root'])
            ->push(['id' => 'thread-reply']),
    ]);

    $result = threadsProvider()->post(threadsIntegration(), [
        new PostPayloadDto(content: 'root'),
        new PostPayloadDto(content: 'reply'),
    ]);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/threads-user-1/threads') || ($request['reply_to_id'] ?? null) === null) {
            return true;
        }

        return $request['reply_to_id'] === 'thread-root';
    });

    expect($result[0]->releaseId)->toBe('thread-root')
        ->and($result[1]->releaseId)->toBe('thread-reply');
});

it('honours a resumed chain\'s reply_to_id on the first segment', function () {
    Http::fake([
        'graph.threads.net/v1.0/threads-user-1/threads' => Http::response(['id' => 'container-resumed']),
        'graph.threads.net/v1.0/container-resumed?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.threads.net/v1.0/threads-user-1/threads_publish' => Http::response(['id' => 'thread-resumed']),
    ]);

    threadsProvider()->post(threadsIntegration(), [
        (new PostPayloadDto(content: 'the tail'))->withReplyToReleaseId('thread-already-published'),
    ]);

    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/threads-user-1/threads')
        || $request['reply_to_id'] === 'thread-already-published');
});

it('throws a partial failure carrying already-published results when a later segment fails', function () {
    Http::fake([
        'graph.threads.net/v1.0/threads-user-1/threads' => Http::sequence()
            ->push(['id' => 'container-root'])
            ->push(['error' => ['message' => 'rejected', 'code' => 100]], 400),
        'graph.threads.net/v1.0/container-root?*' => Http::response(['status_code' => 'FINISHED']),
        'graph.threads.net/v1.0/threads-user-1/threads_publish' => Http::response(['id' => 'thread-root']),
    ]);

    try {
        threadsProvider()->post(threadsIntegration(), [
            new PostPayloadDto(content: 'root'),
            new PostPayloadDto(content: 'this one fails'),
        ]);
        test()->fail('Expected a partial failure exception.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveKey(0)
            ->and($e->published[0]->releaseId)->toBe('thread-root')
            ->and($e->published)->not->toHaveKey(1);
    }
});
