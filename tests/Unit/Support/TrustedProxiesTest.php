<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Env;

/*
 * Production runs behind Caddy (TLS) -> nginx -> php-fpm. Laravel only honours
 * X-Forwarded-Proto/-For from a proxy it has been told to trust; without that
 * it sees plain HTTP and Caddy's own address for every request, so secure
 * cookies, signed-URL schemes and every per-IP throttle (login!) break.
 *
 * It must NOT be "trust everything": with `*` anyone who reaches php-fpm
 * directly could forge X-Forwarded-For and walk around the login throttle, so
 * the default is nobody and production names Caddy's network explicitly.
 */

/** @return array<string, mixed> */
function trustedProxyConfigWith(?string $env): array
{
    $repository = Env::getRepository();
    $repository->clear('TRUSTED_PROXIES');

    if ($env !== null) {
        $repository->set('TRUSTED_PROXIES', $env);
    }

    try {
        return require config_path('trustedproxy.php');
    } finally {
        $repository->clear('TRUSTED_PROXIES');
    }
}

it('reads the trusted proxies from TRUSTED_PROXIES and trusts nobody when it is unset', function () {
    expect(trustedProxyConfigWith(null)['proxies'])->toBeNull()
        ->and(trustedProxyConfigWith('172.30.0.0/24,10.0.0.1')['proxies'])->toBe('172.30.0.0/24,10.0.0.1');
});

/**
 * Runs the framework's own middleware over a request that arrived from
 * $remoteAddr claiming to be an https request from the client 203.0.113.9.
 * Called directly rather than through a route: the SPA catch-all in
 * routes/web.php would swallow a probe route added after boot.
 */
function requestVia(string $remoteAddr): Request
{
    $request = Request::create('/', 'GET', server: [
        'REMOTE_ADDR' => $remoteAddr,
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
    ]);

    (new TrustProxies)->handle($request, fn () => response('ok'));

    return $request;
}

// Symfony keeps the trusted list in static state; leave none behind for later tests.
afterEach(fn () => Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR));

it('is part of the global middleware stack', function () {
    expect(app(Kernel::class)->getGlobalMiddleware())->toContain(TrustProxies::class);
});

it('honours forwarded headers from a configured proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8']);

    $request = requestVia('10.1.2.3');

    expect($request->isSecure())->toBeTrue()
        ->and($request->ip())->toBe('203.0.113.9');
});

it('ignores forwarded headers from a caller that is not a configured proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8']);

    $request = requestVia('198.51.100.7');

    expect($request->isSecure())->toBeFalse()
        ->and($request->ip())->toBe('198.51.100.7');
});

it('ignores forwarded headers from everyone when no proxy is configured', function () {
    config(['trustedproxy.proxies' => null]);

    $request = requestVia('10.1.2.3');

    expect($request->isSecure())->toBeFalse()
        ->and($request->ip())->toBe('10.1.2.3');
});
