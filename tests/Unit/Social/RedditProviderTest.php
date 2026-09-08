<?php

use App\Models\Integration;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SupportsMedia;
use App\Social\Contracts\SupportsOptions;
use App\Social\Contracts\SupportsRefresh;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Exceptions\UnknownProviderOptionException;
use App\Social\ProviderRegistry;
use App\Social\Providers\RedditProvider;
use App\Support\SafeRemoteHost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Reddit adapter. OAuth against one operator-registered app
 * (config('services.reddit')) -- NOT ManualTokenAuth, NOT
 * DeclaresAuthFields, so `GET /api/providers` reports
 * `auth_type: 'oauth'` with an empty `auth_fields`, which is what lets
 * the SPA's fast-connect branch skip the modal entirely.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.reddit.client_id' => 'reddit-client-id',
        'services.reddit.client_secret' => 'reddit-client-secret',
        'services.reddit.user_agent' => 'easypost/1.0 by /u/operator',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function redditIntegration(array $overrides = []): Integration
{
    return Integration::factory()->create(array_merge([
        'provider_identifier' => 'reddit',
        'token' => 'reddit-access-token',
        'refresh_token' => 'reddit-refresh-token',
        'internal_id' => 't2_abc123',
        'settings' => [],
    ], $overrides));
}

function redditProvider(): RedditProvider
{
    $provider = app(ProviderRegistry::class)->get('reddit');

    assert($provider instanceof RedditProvider);

    return $provider;
}

// --- registry / contracts ------------------------------------------------

it('resolves reddit from the provider registry as an oauth-only provider', function () {
    $provider = redditProvider();

    expect($provider)->toBeInstanceOf(RedditProvider::class)
        ->and($provider->identifier())->toBe('reddit')
        ->and($provider)->not->toBeInstanceOf(ManualTokenAuth::class)
        ->and($provider)->not->toBeInstanceOf(DeclaresAuthFields::class)
        ->and($provider)->toBeInstanceOf(SupportsRefresh::class)
        ->and($provider)->toBeInstanceOf(SupportsOptions::class)
        ->and($provider)->toBeInstanceOf(SupportsMedia::class);
});

// --- generateAuthUrl() -----------------------------------------------------

it('builds an authorize url carrying the state unmodified, permanent duration and scopes', function () {
    $url = redditProvider()->generateAuthUrl('opaque-state-value', 'https://app.test/integrations/callback');

    parse_str((string) parse_url($url->url, PHP_URL_QUERY), $query);

    expect($url->url)->toStartWith('https://www.reddit.com/api/v1/authorize?')
        ->and($query['state'])->toBe('opaque-state-value')
        ->and($query['redirect_uri'])->toBe('https://app.test/integrations/callback')
        ->and($query['duration'])->toBe('permanent')
        ->and($query['client_id'])->toBe('reddit-client-id')
        ->and($query['scope'])->toContain('submit')->toContain('flair')->toContain('mysubreddits')
        ->and($url->extra)->toBe([]);
});

it('fails permanently when the client id is not configured', function () {
    config(['services.reddit.client_id' => null]);

    redditProvider()->generateAuthUrl('state', 'https://app.test/callback');
})->throws(ProviderPermanentException::class);

// --- authenticate() ---------------------------------------------------------

it('exchanges the code via http basic auth and fetches the profile', function () {
    Http::fake([
        'www.reddit.com/api/v1/access_token' => Http::response([
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 3600,
        ]),
        'oauth.reddit.com/api/v1/me' => Http::response([
            'id' => 't2_xyz789',
            'name' => 'scheduler_bot',
            'icon_img' => 'https://reddit.com/avatar.png?a=b&amp;c=d',
        ]),
    ]);

    $result = redditProvider()->authenticate(['code' => 'auth-code-123'], 'https://app.test/integrations/callback');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/access_token')) {
            return true;
        }

        return $request->hasHeader('Authorization')
            && str_starts_with($request->header('Authorization')[0], 'Basic ')
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code-123';
    });

    expect($result->internalId)->toBe('t2_xyz789')
        ->and($result->name)->toBe('u/scheduler_bot')
        ->and($result->token)->toBe('fresh-access-token')
        ->and($result->refreshToken)->toBe('fresh-refresh-token')
        ->and($result->expiresAt?->diffInSeconds(now()->addSeconds(3600)))->toBeLessThan(2)
        ->and($result->picture)->toBe('https://reddit.com/avatar.png?a=b&c=d');
});

it('rejects authenticate() with no code before any network call', function () {
    redditProvider()->authenticate([], 'https://app.test/callback');
})->throws(ProviderAuthException::class);

// --- refresh() ---------------------------------------------------------------

it('refreshes the access token and keeps the stored refresh token when reddit issues none', function () {
    Http::fake([
        'www.reddit.com/api/v1/access_token' => Http::response(['access_token' => 'rotated-access-token', 'expires_in' => 3600]),
        'oauth.reddit.com/api/v1/me' => Http::response(['id' => 't2_abc123', 'name' => 'scheduler_bot']),
    ]);

    $integration = redditIntegration();
    $result = redditProvider()->refresh($integration);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/api/v1/access_token')
        || $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'reddit-refresh-token');

    expect($result->token)->toBe('rotated-access-token')
        ->and($result->refreshToken)->toBe('reddit-refresh-token');
});

it('throws a permanent auth exception when refresh has no stored refresh token', function () {
    $integration = redditIntegration(['refresh_token' => null]);

    redditProvider()->refresh($integration);
})->throws(ProviderAuthException::class);

it('maps a rejected refresh to an auth exception', function () {
    Http::fake(['www.reddit.com/api/v1/access_token' => Http::response(['error' => 'invalid_grant'], 400)]);

    redditProvider()->refresh(redditIntegration());
})->throws(ProviderAuthException::class);

// --- User-Agent -------------------------------------------------------------

it('attaches the configured user agent to every authenticated call', function () {
    Http::fake([
        'oauth.reddit.com/*' => Http::response(['json' => ['data' => ['name' => 't3_abc']]]),
    ]);

    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'hello', settings: ['subreddit' => 'test', 'title' => 'A title']),
    ]);

    Http::assertSent(fn ($request) => $request->header('User-Agent')[0] === 'easypost/1.0 by /u/operator');
});

it('fails permanently before any request when the user agent is not configured', function () {
    config(['services.reddit.user_agent' => null]);

    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'hello', settings: ['subreddit' => 'test', 'title' => 'A title']),
    ]);
})->throws(ProviderPermanentException::class);

// --- post() / submit shape ---------------------------------------------------

it('submits a self post when there is no media or url', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_self1', 'url' => 'https://reddit.com/r/test/comments/self1']]])]);

    $result = redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'body text', settings: ['subreddit' => 'test', 'title' => 'A title']),
    ]);

    Http::assertSent(fn ($request) => $request['kind'] === 'self' && $request['text'] === 'body text' && $request['sr'] === 'test' && $request['title'] === 'A title');
    expect($result[0]->releaseId)->toBe('t3_self1')
        ->and($result[0]->releaseUrl)->toBe('https://reddit.com/r/test/comments/self1');
});

it('submits a link post when a url setting is present and no media is attached', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_link1']]])]);

    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'ignored for a link post', settings: ['subreddit' => 'test', 'title' => 'A title', 'url' => 'https://example.test/article']),
    ]);

    Http::assertSent(fn ($request) => $request['kind'] === 'link' && $request['url'] === 'https://example.test/article');
});

it('includes the flair id when one is set', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_flair1']]])]);

    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'body', settings: ['subreddit' => 'test', 'title' => 'title', 'flair_id' => 'flair-abc']),
    ]);

    Http::assertSent(fn ($request) => $request['flair_id'] === 'flair-abc');
});

it('submits an image post from a single pre-uploaded media item', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_img1']]])]);

    $media = (new MediaDto(path: '/tmp/x.png', mimeType: 'image/png'))
        ->withRemoteId((string) json_encode(['asset_id' => 'asset-1', 'url' => 'https://i.redd.it/asset-1']));

    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'ignored', media: [$media], settings: ['subreddit' => 'test', 'title' => 'title']),
    ]);

    Http::assertSent(fn ($request) => $request['kind'] === 'image' && $request['url'] === 'https://i.redd.it/asset-1');
});

it('submits a gallery post from multiple pre-uploaded media items', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_gal1']]])]);

    $first = (new MediaDto(path: '/tmp/a.png', mimeType: 'image/png'))->withRemoteId((string) json_encode(['asset_id' => 'asset-1', 'url' => 'https://i.redd.it/asset-1']));
    $second = (new MediaDto(path: '/tmp/b.png', mimeType: 'image/png'))->withRemoteId((string) json_encode(['asset_id' => 'asset-2', 'url' => 'https://i.redd.it/asset-2']));

    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'ignored', media: [$first, $second], settings: ['subreddit' => 'test', 'title' => 'title']),
    ]);

    Http::assertSent(function ($request) {
        if ($request['kind'] !== 'gallery') {
            return false;
        }

        $items = json_decode($request['items'], true);

        return count($items) === 2 && $items[0]['asset_id'] === 'asset-1' && $items[1]['asset_id'] === 'asset-2';
    });
});

it('rejects a post whose media was never uploaded via uploadMedia', function () {
    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'x', media: [new MediaDto(path: '/tmp/a.png', mimeType: 'image/png')], settings: ['subreddit' => 'test', 'title' => 't']),
    ]);
})->throws(ProviderPermanentException::class);

// --- threads: submission + comments ------------------------------------------

it('publishes a chain as one submission followed by comments anchored to the previous segment', function () {
    Http::fake([
        'oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_root']]]),
        'oauth.reddit.com/api/comment' => Http::sequence()
            ->push(['json' => ['data' => ['things' => [['data' => ['name' => 't1_c1']]]]]])
            ->push(['json' => ['data' => ['things' => [['data' => ['name' => 't1_c2']]]]]]),
    ]);

    $result = redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: 'root', settings: ['subreddit' => 'test', 'title' => 'title']),
        new PostPayloadDto(content: 'reply one'),
        new PostPayloadDto(content: 'reply two'),
    ]);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/api/comment') || in_array($request['thing_id'], ['t3_root', 't1_c1'], true));

    expect($result[0]->releaseId)->toBe('t3_root')
        ->and($result[1]->releaseId)->toBe('t1_c1')
        ->and($result[2]->releaseId)->toBe('t1_c2');
});

it('anchors a resumed chain to replyToReleaseId via api/comment and issues no submit call', function () {
    Http::fake(['oauth.reddit.com/api/comment' => Http::response(['json' => ['data' => ['things' => [['data' => ['name' => 't1_resumed']]]]]])]);

    $result = redditProvider()->post(redditIntegration(), [
        (new PostPayloadDto(content: 'resumed reply'))->withReplyToReleaseId('t3_previously_published'),
    ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/submit'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/comment') && $request['thing_id'] === 't3_previously_published');
    expect($result[0]->releaseId)->toBe('t1_resumed');
});

it('throws a partial failure exception carrying already-published segments when a later segment fails', function () {
    Http::fake([
        'oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_root']]]),
        'oauth.reddit.com/api/comment' => Http::response(['json' => ['errors' => [['SOMETHING', 'bad', 'field']]]]),
    ]);

    try {
        redditProvider()->post(redditIntegration(), [
            new PostPayloadDto(content: 'root', settings: ['subreddit' => 'test', 'title' => 'title']),
            new PostPayloadDto(content: 'reply'),
        ]);

        test()->fail('Expected a partial failure exception.');
    } catch (ProviderPartialFailureException $e) {
        expect($e->published)->toHaveCount(1)
            ->and($e->published[0]->releaseId)->toBe('t3_root');
    }
});

// --- error mapping -------------------------------------------------------

it('maps a 200 envelope carrying errors to a permanent exception', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['errors' => [['SUBREDDIT_NOEXIST', 'that subreddit does not exist', 'sr']]]])]);

    redditProvider()->post(redditIntegration(), [new PostPayloadDto(content: 'x', settings: ['subreddit' => 'nope', 'title' => 't'])]);
})->throws(ProviderPermanentException::class);

it('maps a RATELIMIT envelope error to a rate limit exception carrying the parsed wait', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['errors' => [['RATELIMIT', 'you are doing that too much. try again in 9 minutes.', 'ratelimit']]]])]);

    try {
        redditProvider()->post(redditIntegration(), [new PostPayloadDto(content: 'x', settings: ['subreddit' => 'test', 'title' => 't'])]);
        test()->fail('Expected a rate limit exception.');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(540);
    }
});

it('maps an http 429 to a rate limit exception', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response('', 429, ['Retry-After' => '30'])]);

    try {
        redditProvider()->post(redditIntegration(), [new PostPayloadDto(content: 'x', settings: ['subreddit' => 'test', 'title' => 't'])]);
        test()->fail('Expected a rate limit exception.');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(30);
    }
});

it('maps an http 401 to an auth exception', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response('', 401)]);

    redditProvider()->post(redditIntegration(), [new PostPayloadDto(content: 'x', settings: ['subreddit' => 'test', 'title' => 't'])]);
})->throws(ProviderAuthException::class);

it('maps an http 5xx to a transient exception', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response('', 503)]);

    redditProvider()->post(redditIntegration(), [new PostPayloadDto(content: 'x', settings: ['subreddit' => 'test', 'title' => 't'])]);
})->throws(ProviderTransientException::class);

it('does not leak the access token through the exception chain on a network-level failure', function () {
    Http::fake(fn () => throw new ConnectionException('connection refused, token=reddit-access-token'));

    try {
        redditProvider()->post(redditIntegration(), [new PostPayloadDto(content: 'x', settings: ['subreddit' => 'test', 'title' => 't'])]);
        test()->fail('Expected a transient exception.');
    } catch (ProviderTransientException $e) {
        expect($e->getMessage())->not->toContain('reddit-access-token')
            ->and($e->getPrevious())->toBeNull();
    }
});

// --- options() ----------------------------------------------------------

it('lists the connected account subreddits', function () {
    Http::fake(['oauth.reddit.com/subreddits/mine/subscriber*' => Http::response([
        'data' => ['children' => [
            ['data' => ['display_name' => 'test', 'display_name_prefixed' => 'r/test']],
        ]],
    ])]);

    $options = redditProvider()->options(redditIntegration(), 'subreddits');

    expect($options)->toBe([['value' => 'test', 'label' => 'r/test']]);
});

it('lists flairs for a chosen subreddit', function () {
    Http::fake(['oauth.reddit.com/r/test/api/link_flair_v2' => Http::response([
        ['id' => 'flair-1', 'text' => 'Discussion'],
    ])]);

    $options = redditProvider()->options(redditIntegration(), 'flairs', ['subreddit' => 'test']);

    expect($options)->toBe([['value' => 'flair-1', 'label' => 'Discussion']]);
});

it('returns an empty flair list when no subreddit context is given yet, without a network call', function () {
    $options = redditProvider()->options(redditIntegration(), 'flairs');

    Http::assertNothingSent();
    expect($options)->toBe([]);
});

it('rejects a malformed subreddit in context before it reaches the request url', function () {
    $options = redditProvider()->options(redditIntegration(), 'flairs', ['subreddit' => '../evil']);

    Http::assertNothingSent();
    expect($options)->toBe([]);
});

it('throws for an unknown option name', function () {
    redditProvider()->options(redditIntegration(), 'boards');
})->throws(UnknownProviderOptionException::class);

it('declares no updatable channel settings', function () {
    expect(redditProvider()->settingOptions())->toBe([]);
});

// --- uploadMedia() --------------------------------------------------------

it('uploads media through the three-leg lease/upload flow and returns a remote id', function () {
    SafeRemoteHost::fake(['reddit-uploads.s3.amazonaws.com' => ['198.51.100.20']]);

    Http::fake([
        'oauth.reddit.com/api/media/asset.json' => Http::response([
            'args' => [
                'action' => '//reddit-uploads.s3.amazonaws.com/upload',
                'fields' => [['name' => 'key', 'value' => 'some-key'], ['name' => 'policy', 'value' => 'some-policy']],
            ],
            'asset' => ['asset_id' => 'asset-99'],
        ]),
        'reddit-uploads.s3.amazonaws.com/*' => Http::response('', 201),
    ]);

    $tmp = tempnam(sys_get_temp_dir(), 'reddit-media-');
    file_put_contents($tmp, 'fake-png-bytes');

    $result = redditProvider()->uploadMedia(redditIntegration(), new MediaDto(path: $tmp, mimeType: 'image/png', originalFilename: 'photo.png'));

    @unlink($tmp);
    SafeRemoteHost::stopFaking();

    $decoded = json_decode((string) $result->remoteId, true);
    expect($decoded['asset_id'])->toBe('asset-99');
});

it('rejects an upload target host that SafeRemoteHost refuses', function () {
    Http::fake([
        'oauth.reddit.com/api/media/asset.json' => Http::response([
            'args' => ['action' => 'https://169.254.169.254/upload', 'fields' => []],
            'asset' => ['asset_id' => 'asset-1'],
        ]),
    ]);

    $tmp = tempnam(sys_get_temp_dir(), 'reddit-media-');
    file_put_contents($tmp, 'bytes');

    try {
        redditProvider()->uploadMedia(redditIntegration(), new MediaDto(path: $tmp, mimeType: 'image/png', originalFilename: 'photo.png'));
        test()->fail('Expected the unsafe upload host to be rejected.');
    } finally {
        @unlink($tmp);
    }
})->throws(ProviderPermanentException::class);

// --- redirect refusal (shares this project's pattern; kept in this file for cohesion) ---

it('refuses a redirect from reddit instead of following it', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(
        'SECRET-BODY-THAT-MUST-NOT-BE-ECHOED',
        302,
        ['Location' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/'],
    )]);

    try {
        redditProvider()->post(redditIntegration(), [new PostPayloadDto(content: 'x', settings: ['subreddit' => 'test', 'title' => 't'])]);
        test()->fail('Expected the provider to refuse the redirect.');
    } catch (ProviderPermanentException $e) {
        expect($e->getMessage())->toContain('redirect')
            ->and($e->getMessage())->not->toContain('169.254.169.254')
            ->and($e->getMessage())->not->toContain('SECRET-BODY-THAT-MUST-NOT-BE-ECHOED');
    }

    Http::assertSentCount(1);
});

// --- capabilities ----------------------------------------------------------

it('declares the full canonical mark set, sent verbatim (no TranslatesMarkup)', function () {
    Http::fake(['oauth.reddit.com/api/submit' => Http::response(['json' => ['data' => ['name' => 't3_x']]])]);

    redditProvider()->post(redditIntegration(), [
        new PostPayloadDto(content: '**bold** and *italic*', settings: ['subreddit' => 'test', 'title' => 't']),
    ]);

    Http::assertSent(fn ($request) => $request['text'] === '**bold** and *italic*');

    $marks = array_map(fn ($m) => $m->value, redditProvider()->capabilities()->supportedMarks);
    expect($marks)->toBe(['BOLD', 'ITALIC', 'STRIKE', 'CODE', 'LINK']);
});
