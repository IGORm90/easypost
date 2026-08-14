<?php

use App\Models\Integration;
use App\Social\Dto\PostPayloadDto;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\ProviderRegistry;
use App\Support\SafeRemoteHost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/*
 * Final-review batch 2, Critical 2: no provider follows a redirect.
 *
 * Before this, every provider call used a client that follows up to five
 * hops, ACROSS HOSTS, allows an https→http downgrade, and rewrites a
 * 301/302/303 POST into a GET — so a Mastodon instance an org admin
 * controls could 302 `/api/v1/statuses` to `http://169.254.169.254/…`
 * and the request would simply go there, past a host check that only ever
 * looked at the FIRST hop. And the answer was not blind: the remote
 * body reaches `posts.error` via `errorFor()`/`markAllError()` and is
 * returned by `PostResource`.
 *
 * Each test below has the network answer 302 with a Location pointing at
 * the cloud-metadata address, and asserts three things:
 *   - the call fails permanently rather than being followed;
 *   - EXACTLY ONE request went out (a followed redirect would be two);
 *   - the exception text leaks neither the Location nor the body — that
 *     message is customer-visible, and this defect is precisely about a
 *     remote host choosing what lands there.
 */

beforeEach(function () {
    SafeRemoteHost::fake([
        'mastodon.social' => ['198.51.100.10'],
        'discord.com' => ['198.51.100.11'],
        'slack.com' => ['198.51.100.12'],
    ]);
});

afterEach(function () {
    SafeRemoteHost::stopFaking();
});

/**
 * A 302 aimed at the cloud-metadata service, with a body a follower
 * would happily surface.
 */
function redirectToMetadata(): mixed
{
    return Http::response(
        'SECRET-BODY-THAT-MUST-NOT-BE-ECHOED',
        302,
        ['Location' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/'],
    );
}

function assertRefusedRedirect(Closure $call): void
{
    try {
        $call();
        test()->fail('Expected the provider to refuse the redirect.');
    } catch (ProviderPermanentException $e) {
        expect($e->getMessage())->toContain('redirect')
            ->and($e->getMessage())->not->toContain('169.254.169.254')
            ->and($e->getMessage())->not->toContain('SECRET-BODY-THAT-MUST-NOT-BE-ECHOED')
            ->and($e->getPrevious())->toBeNull();
    }

    Http::assertSentCount(1);
}

it('refuses a redirect from telegram instead of following it', function () {
    Http::fake(['api.telegram.org/*' => redirectToMetadata()]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'telegram',
        'token' => 'bot-token',
        'settings' => ['chat_id' => '-100'],
    ]);

    assertRefusedRedirect(fn () => app(ProviderRegistry::class)->get('telegram')
        ->post($integration, [new PostPayloadDto(content: 'hello')]));
});

it('refuses a redirect from mastodon instead of following it', function () {
    Http::fake(['mastodon.social/*' => redirectToMetadata()]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'mastodon',
        'token' => 'access-token',
        'settings' => ['instance' => 'mastodon.social'],
    ]);

    assertRefusedRedirect(fn () => app(ProviderRegistry::class)->get('mastodon')
        ->post($integration, [new PostPayloadDto(content: 'hello')]));
});

it('refuses a redirect from bluesky instead of following it', function () {
    Http::fake(['bsky.social/*' => redirectToMetadata()]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'bluesky',
        'token' => 'jwt',
        'internal_id' => 'did:plc:abc',
    ]);

    assertRefusedRedirect(fn () => app(ProviderRegistry::class)->get('bluesky')
        ->post($integration, [new PostPayloadDto(content: 'hello')]));
});

it('refuses a redirect from discord instead of following it', function () {
    Http::fake(['discord.com/*' => redirectToMetadata()]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'discord',
        'token' => 'https://discord.com/api/webhooks/1/secret',
    ]);

    assertRefusedRedirect(fn () => app(ProviderRegistry::class)->get('discord')
        ->post($integration, [new PostPayloadDto(content: 'hello')]));
});

it('refuses a redirect from slack instead of following it', function () {
    Http::fake(['slack.com/*' => redirectToMetadata()]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'slack',
        'token' => 'xoxb-token',
        'settings' => ['channel_id' => 'C1'],
    ]);

    assertRefusedRedirect(fn () => app(ProviderRegistry::class)->get('slack')
        ->post($integration, [new PostPayloadDto(content: 'hello')]));
});

it('builds every provider request with redirect-following switched off', function () {
    // The refusals above prove the RESPONSE side. This proves the
    // REQUEST side: even a client that would otherwise follow silently
    // (a future Guzzle default, a redirect on a status this codebase
    // does not enumerate) is told not to, so there is no second request
    // for anything downstream to have to catch.
    $provider = app(ProviderRegistry::class)->get('slack');

    foreach (['http', 'uploadHttp'] as $builder) {
        $method = new ReflectionMethod($provider, $builder);
        /** @var PendingRequest $request */
        $request = $method->invoke($provider);

        expect($request->getOptions()['allow_redirects'])->toBeFalse("{$builder}() follows redirects");
    }
});
