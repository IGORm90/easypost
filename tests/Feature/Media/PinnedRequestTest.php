<?php

use App\Services\Media\MediaUploadService;
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
 *
 * One call site is left to cover: the media import download. The others
 * were provider legs — a user-named instance host, a stored webhook url,
 * an upload url the network itself handed back — and they went when
 * those providers did. A new provider that resolves a caller-named host
 * belongs here the day it is written, not after the first report.
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
