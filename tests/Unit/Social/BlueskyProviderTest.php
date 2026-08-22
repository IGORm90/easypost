<?php

use App\Models\Integration;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use App\Social\Providers\BlueskyProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Task 15: AT Protocol adapter. Not OAuth -- a Bluesky account is
 * connected via a handle + app password (createSession), so
 * BlueskyProvider implements ManualTokenAuth like TelegramProvider.
 * Sessions expire and are renewed via refreshSession -- SupportsRefresh.
 *
 * See PostPayloadDto's class docblock for the documented strong-ref
 * reconstruction mechanism this provider implements: a Bluesky reply
 * needs both a `root` and `parent` strong ref (uri + cid), but
 * $replyToReleaseId only carries the parent's id (a bare uri, since
 * PostResultDto has no cid field) -- so on chain resumption the
 * provider fetches the parent record via com.atproto.repo.getRecord to
 * recover its cid and (if the parent is itself a reply) its embedded
 * root reference.
 */

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @return array<string, mixed>
 */
function blueskyFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/social/{$name}.json")), true);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function blueskyIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'bluesky',
        'token' => 'bluesky-access-jwt',
        'refresh_token' => 'bluesky-refresh-jwt',
        'internal_id' => 'did:plc:abc123xyz456',
        'settings' => ['handle' => 'scheduler.bsky.social'],
    ], $overrides));
}

// --- registry ----------------------------------------------------------

it('resolves bluesky from the provider registry', function () {
    $provider = app(ProviderRegistry::class)->get('bluesky');

    expect($provider)->toBeInstanceOf(BlueskyProvider::class)
        ->and($provider->identifier())->toBe('bluesky')
        ->and($provider)->toBeInstanceOf(ManualTokenAuth::class)
        ->and($provider)->toBeInstanceOf(SupportsRefresh::class);
});

// --- authenticate() ------------------------------------------------------

it('authenticates via createSession with a handle and app password', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(blueskyFixture('bluesky_create_session')),
    ]);

    $provider = app(ProviderRegistry::class)->get('bluesky');
    $result = $provider->authenticate(['handle' => 'scheduler.bsky.social', 'app_password' => 'abcd-efgh-ijkl-mnop'], 'https://app.test/callback');

    expect($result->internalId)->toBe('did:plc:abc123xyz456')
        ->and($result->name)->toBe('scheduler.bsky.social')
        ->and($result->token)->toBe('eyJhbGciOiJIUzI1NiJ9.access-token-payload.signature')
        ->and($result->refreshToken)->toBe('eyJhbGciOiJIUzI1NiJ9.refresh-token-payload.signature')
        ->and($result->settings)->toBe(['handle' => 'scheduler.bsky.social']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createSession')
        && $request['identifier'] === 'scheduler.bsky.social'
        && $request['password'] === 'abcd-efgh-ijkl-mnop');
});

it('maps a createSession rejection to an auth exception', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(blueskyFixture('bluesky_error_unauthorized'), 401),
    ]);

    $provider = app(ProviderRegistry::class)->get('bluesky');

    expect(fn () => $provider->authenticate(['handle' => 'scheduler.bsky.social', 'app_password' => 'bad'], 'https://app.test/callback'))
        ->toThrow(ProviderAuthException::class);
});

it('rejects authenticate() with missing credentials before any network call', function () {
    $provider = app(ProviderRegistry::class)->get('bluesky');

    expect(fn () => $provider->authenticate(['handle' => 'scheduler.bsky.social'], 'https://app.test/callback'))
        ->toThrow(InvalidArgumentException::class);
});

// --- refresh() (SupportsRefresh) ----------------------------------------

it('refreshes the session via refreshSession using the stored refresh token', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response(blueskyFixture('bluesky_refresh_session')),
    ]);

    $integration = blueskyIntegration();
    $provider = app(ProviderRegistry::class)->get('bluesky');
    expect($provider)->toBeInstanceOf(SupportsRefresh::class);
    /** @var SupportsRefresh $provider */
    $result = $provider->refresh($integration);

    expect($result->token)->toBe('eyJhbGciOiJIUzI1NiJ9.new-access-token-payload.signature')
        ->and($result->refreshToken)->toBe('eyJhbGciOiJIUzI1NiJ9.new-refresh-token-payload.signature');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'refreshSession')
        && $request->hasHeader('Authorization', 'Bearer bluesky-refresh-jwt'));
});

it('maps a refreshSession rejection to an auth exception', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response(blueskyFixture('bluesky_error_unauthorized'), 401),
    ]);

    $integration = blueskyIntegration();
    $provider = app(ProviderRegistry::class)->get('bluesky');
    expect($provider)->toBeInstanceOf(SupportsRefresh::class);
    /** @var SupportsRefresh $provider */
    expect(fn () => $provider->refresh($integration))->toThrow(ProviderAuthException::class);
});

// --- uploadMedia() -------------------------------------------------------

it('uploads a blob and returns a MediaDto carrying the remote id', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response(blueskyFixture('bluesky_upload_blob')),
    ]);

    $integration = blueskyIntegration();
    $media = new MediaDto(path: base_path('tests/Fixtures/social/sample.png'), mimeType: 'image/png', altText: 'A screenshot');
    $provider = app(ProviderRegistry::class)->get('bluesky');
    expect($provider)->toBeInstanceOf(BlueskyProvider::class);
    /** @var BlueskyProvider $provider */
    $uploaded = $provider->uploadMedia($integration, $media);

    expect($uploaded->remoteId)->not->toBeNull()
        ->and(json_decode((string) $uploaded->remoteId, true)['ref']['$link'])->toBe('bafkreih6c6mfsxqhojcvzjzq3ta3xsrsz7ux5po4nrsg4vlmzfl3rvgqzu');
});

// --- post(): happy path --------------------------------------------------

it('publishes a post and returns the release id', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_create_record')),
    ]);

    $integration = blueskyIntegration();
    $result = app(ProviderRegistry::class)->get('bluesky')->post($integration, [new PostPayloadDto('hello')]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->releaseId)->toBe('at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord')
        && $request['repo'] === 'did:plc:abc123xyz456'
        && $request['collection'] === 'app.bsky.feed.post'
        && $request['record']['text'] === 'hello'
        && $request->hasHeader('Authorization', 'Bearer bluesky-access-jwt'));
});

it('publishes a thread, carrying root and parent strong refs for each reply', function () {
    Http::fakeSequence('bsky.social/xrpc/com.atproto.repo.createRecord')
        ->push(blueskyFixture('bluesky_create_record'))
        ->push(blueskyFixture('bluesky_create_record_reply'));

    $integration = blueskyIntegration();
    $chain = [new PostPayloadDto('first segment'), new PostPayloadDto('second segment')];
    $result = app(ProviderRegistry::class)->get('bluesky')->post($integration, $chain);

    expect($result)->toHaveCount(2)
        ->and($result[0]->releaseId)->toBe('at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001')
        ->and($result[1]->releaseId)->toBe('at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcreply002');

    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['reply']),
        fn ($request) => str_contains($request->url(), 'createRecord')
            && $request['record']['reply']['root']['uri'] === 'at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001'
            && $request['record']['reply']['parent']['uri'] === 'at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001',
    ]);
});

// --- error mapping -------------------------------------------------------

it('rejects content over the bluesky character limit with a permanent exception', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_error_bad_request'), 400),
    ]);

    $integration = blueskyIntegration();
    $tooLong = str_repeat('a', 301);

    expect(fn () => app(ProviderRegistry::class)->get('bluesky')->post($integration, [new PostPayloadDto($tooLong)]))
        ->toThrow(ProviderPermanentException::class);
});

it('maps a bluesky 429 to a rate limit exception', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_error_rate_limit'), 429, ['Retry-After' => '30']),
    ]);

    $integration = blueskyIntegration();

    try {
        app(ProviderRegistry::class)->get('bluesky')->post($integration, [new PostPayloadDto('hello')]);
        test()->fail('Expected ProviderRateLimitException.');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(30);
    }
});

it('maps a bluesky 401 to an auth exception', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_error_unauthorized'), 401),
    ]);

    $integration = blueskyIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('bluesky')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderAuthException::class);
});

it('maps a bluesky 5xx to a transient exception', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_error_server'), 503),
    ]);

    $integration = blueskyIntegration();

    expect(fn () => app(ProviderRegistry::class)->get('bluesky')->post($integration, [new PostPayloadDto('hello')]))
        ->toThrow(ProviderTransientException::class);
});

// --- thread resumption: strong-ref reconstruction -----------------------

it('reconstructs root and parent strong refs from the anchor when resuming a thread whose parent is itself a reply', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.getRecord*' => Http::response(blueskyFixture('bluesky_get_record_reply')),
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_create_record_reply')),
    ]);

    $integration = blueskyIntegration();
    $resumedChain = [
        (new PostPayloadDto('third segment'))->withReplyToReleaseId('at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcreply002'),
    ];
    $result = app(ProviderRegistry::class)->get('bluesky')->post($integration, $resumedChain);

    expect($result[0]->releaseId)->toBe('at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcreply002');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'getRecord')
        && $request['repo'] === 'did:plc:abc123xyz456'
        && $request['rkey'] === '3k2abcreply002');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord')
        // Root is the resumed record's OWN root (the original thread root),
        // not the resumed record itself -- since that record was itself a reply.
        && $request['record']['reply']['root']['uri'] === 'at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001'
        && $request['record']['reply']['root']['cid'] === 'bafyreigh2akiscaildcroot0010000000000000000000000000000000000'
        // Parent is the anchor itself.
        && $request['record']['reply']['parent']['uri'] === 'at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcreply002'
        && $request['record']['reply']['parent']['cid'] === 'bafyreigh2akiscaildcreply0020000000000000000000000000000000000');
});

it('treats the anchor as its own root when it has no reply field', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.getRecord*' => Http::response(blueskyFixture('bluesky_get_record_root')),
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_create_record_reply')),
    ]);

    $integration = blueskyIntegration();
    $resumedChain = [
        (new PostPayloadDto('second segment'))->withReplyToReleaseId('at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001'),
    ];
    app(ProviderRegistry::class)->get('bluesky')->post($integration, $resumedChain);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord')
        && $request['record']['reply']['root']['uri'] === 'at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001'
        && $request['record']['reply']['parent']['uri'] === 'at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001');
});

it('throws a permanent exception when the anchor record can no longer be resolved', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound', 'message' => 'Could not locate record'], 400),
    ]);

    $integration = blueskyIntegration();
    $resumedChain = [(new PostPayloadDto('third segment'))->withReplyToReleaseId('at://did:plc:abc123xyz456/app.bsky.feed.post/missing')];

    expect(fn () => app(ProviderRegistry::class)->get('bluesky')->post($integration, $resumedChain))
        ->toThrow(ProviderPermanentException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'createRecord'));
});

// --- partial failure -----------------------------------------------------

it('throws a partial failure exception carrying already-published segments when a later segment fails', function () {
    Http::fakeSequence('bsky.social/xrpc/com.atproto.repo.createRecord')
        ->push(blueskyFixture('bluesky_create_record'))
        ->push(blueskyFixture('bluesky_error_bad_request'), 400);

    $integration = blueskyIntegration();
    $chain = [new PostPayloadDto('first'), new PostPayloadDto('second'), new PostPayloadDto('third')];

    try {
        app(ProviderRegistry::class)->get('bluesky')->post($integration, $chain);
        test()->fail('Expected ProviderPartialFailureException.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveCount(1)
            ->and($e->published[0]->releaseId)->toBe('at://did:plc:abc123xyz456/app.bsky.feed.post/3k2abcroot001');
    }
});

// --- secret-leak protection -----------------------------------------------

it('does not leak the app password or session tokens through the exception chain on a network-level failure', function () {
    $appPassword = 'super-secret-app-password-abcd';
    $accessToken = 'super-secret-bluesky-access-jwt';

    Http::fake([
        'bsky.social/*' => function () use ($appPassword, $accessToken) {
            throw new ConnectionException(
                "cURL error 6: Could not resolve host for https://bsky.social/xrpc/com.atproto.repo.createRecord (password={$appPassword}, Authorization: Bearer {$accessToken})",
            );
        },
    ]);

    $integration = blueskyIntegration(['token' => $accessToken]);

    try {
        app(ProviderRegistry::class)->get('bluesky')->post($integration, [new PostPayloadDto('hello')]);
        test()->fail('Expected ProviderTransientException.');
    } catch (ProviderTransientException $e) {
        $messages = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        expect($messages)->not->toBeEmpty();

        foreach ($messages as $message) {
            expect($message)->not->toContain($appPassword)
                ->and($message)->not->toContain($accessToken);
        }
    }
});

// --- Block 3: no markup on a plain-text network ---------------------------

it('declares no text marks and publishes markup characters verbatim', function () {
    // An AT Protocol post is plain text plus facets, and this provider
    // builds no facets -- so nothing here can render bold, nothing is
    // offered, and the body goes out exactly as typed.
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(blueskyFixture('bluesky_create_record')),
    ]);

    $provider = app(ProviderRegistry::class)->get('bluesky');

    expect($provider->capabilities()->supportedMarks)->toBe([]);

    $provider->post(blueskyIntegration(), [new PostPayloadDto('a **bold** claim & 5 < 6')]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord')
        && $request['record']['text'] === 'a **bold** claim & 5 < 6');
});
