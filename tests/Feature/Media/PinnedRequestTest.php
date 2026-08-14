<?php

use App\Models\Integration;
use App\Services\Media\MediaUploadService;
use App\Social\Contracts\SupportsMedia;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\ProviderRegistry;
use App\Support\SafeRemoteHost;
use Illuminate\Support\Facades\Http;

/*
 * Re-review finding 2: the suite proved `pinOptions()`'s SHAPE and proved
 * that `CURLOPT_RESOLVE` governs real cURL, but nothing proved that any
 * production call site actually PASSES the pin to the request it makes —
 * stripping `+ $pin` / `->withOptions($pin)` everywhere left the whole
 * suite green. That is this project's named failure mode landing inside
 * the security fix itself.
 *
 * These tests capture the Guzzle options of every request a call site
 * actually issues, via `Http::globalMiddleware()` (a real handler-stack
 * middleware, which receives `$options`, unlike the request-only
 * middleware hooks), and assert the pin is in them, keyed to the host and
 * port, carrying the exact address SafeRemoteHost validated.
 */

/**
 * Records the Guzzle options of every outbound request for the duration
 * of $callback, and returns them.
 *
 * @param  Closure(): mixed  $callback
 * @return list<array<string, mixed>>
 */
function captureRequestOptions(Closure $callback): array
{
    $captured = [];

    Http::globalMiddleware(function (callable $handler) use (&$captured): Closure {
        return function ($request, array $options) use ($handler, &$captured) {
            $captured[] = $options;

            return $handler($request, $options);
        };
    });

    try {
        $callback();
    } finally {
        // globalMiddleware has no public removal API; the factory is
        // rebuilt per test by the container, but this makes the intent
        // explicit for anyone reading a failure.
    }

    return $captured;
}

/**
 * @param  list<array<string, mixed>>  $captured
 */
function assertEveryRequestPinned(array $captured, string $expectedResolve): void
{
    expect($captured)->not->toBe([], 'no request was captured — the assertion never ran');

    foreach ($captured as $index => $options) {
        expect($options['curl'][CURLOPT_RESOLVE] ?? null)
            ->toBe([$expectedResolve], "request #{$index} was sent without the validated address pinned");
    }
}

beforeEach(function () {
    SafeRemoteHost::fake([
        'files.example.com' => ['198.51.100.10'],
        'mastodon.social' => ['198.51.100.20'],
        'discord.com' => ['198.51.100.30'],
        'files.slack.com' => ['198.51.100.40'],
        'slack.com' => ['198.51.100.41'],
    ]);
});

afterEach(function () {
    SafeRemoteHost::stopFaking();
});

it('pins the validated address on the media import download', function () {
    Http::fake(['files.example.com/*' => Http::response('IMAGEBYTES')]);

    $service = app(MediaUploadService::class);
    $download = new ReflectionMethod($service, 'download');

    $captured = captureRequestOptions(function () use ($download, $service): void {
        $path = $download->invoke($service, 'http://files.example.com/a.png');
        @unlink($path);
    });

    assertEveryRequestPinned($captured, 'files.example.com:80:198.51.100.10');
});

it('pins the validated instance address on every mastodon publish request', function () {
    Http::fake([
        'mastodon.social/api/v2/media' => Http::response(['id' => '9']),
        'mastodon.social/api/v1/statuses' => Http::response(['id' => '1', 'url' => 'https://mastodon.social/@a/1']),
    ]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'mastodon',
        'token' => 'access-token',
        'settings' => ['instance' => 'mastodon.social'],
    ]);

    $file = tempnam(sys_get_temp_dir(), 'pin-test-');
    file_put_contents($file, 'IMAGEBYTES');

    $captured = captureRequestOptions(function () use ($integration, $file): void {
        $provider = app(ProviderRegistry::class)->get('mastodon');
        assert($provider instanceof SupportsMedia);
        $media = $provider->uploadMedia($integration, new MediaDto(path: $file, mimeType: 'image/png', sizeBytes: 10));
        $provider->post($integration, [new PostPayloadDto(content: 'hi', media: [$media])]);
    });

    @unlink($file);

    // Both the upload and the status call, not just whichever one a
    // reader happens to check.
    expect($captured)->toHaveCount(2);
    assertEveryRequestPinned($captured, 'mastodon.social:443:198.51.100.20');
});

it('pins the validated address on the discord webhook call', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => '1'])]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'discord',
        'token' => 'https://discord.com/api/webhooks/1/secret',
    ]);

    $captured = captureRequestOptions(function () use ($integration): void {
        app(ProviderRegistry::class)->get('discord')
            ->post($integration, [new PostPayloadDto(content: 'hi')]);
    });

    assertEveryRequestPinned($captured, 'discord.com:443:198.51.100.30');
});

it('pins the validated address on the slack upload url, which slack itself supplied', function () {
    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1', 'file_id' => 'F1']),
        'files.slack.com/*' => Http::response('OK'),
    ]);

    $integration = Integration::factory()->create([
        'provider_identifier' => 'slack',
        'token' => 'xoxb-token',
        'settings' => ['channel_id' => 'C1'],
    ]);

    $file = tempnam(sys_get_temp_dir(), 'pin-test-');
    file_put_contents($file, 'IMAGEBYTES');

    $captured = captureRequestOptions(function () use ($integration, $file): void {
        $provider = app(ProviderRegistry::class)->get('slack');
        assert($provider instanceof SupportsMedia);
        $provider->uploadMedia($integration, new MediaDto(path: $file, mimeType: 'image/png', sizeBytes: 10));
    });

    @unlink($file);

    // Only the upload leg is pinned — the API leg targets slack.com,
    // a fixed host this provider never resolves (see SafeRemoteHost's
    // docblock on which call sites pin and why).
    $uploadLeg = array_values(array_filter(
        $captured,
        fn (array $options): bool => isset($options['curl'][CURLOPT_RESOLVE]),
    ));

    assertEveryRequestPinned($uploadLeg, 'files.slack.com:443:198.51.100.40');
});
