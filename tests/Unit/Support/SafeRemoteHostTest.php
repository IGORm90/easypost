<?php

use App\Support\Exceptions\UnsafeRemoteHostException;
use App\Support\SafeRemoteHost;

/*
 * Task 14 review, Finding 1 (CRITICAL): the SSRF guard shared by any
 * feature that makes a server-side request to a user-supplied host.
 * DNS is faked via SafeRemoteHost::fake() (a static test seam, same
 * shape as App\Support\OrganizationContext::set()/clear()) so these
 * tests never touch real DNS or network.
 */

afterEach(function () {
    SafeRemoteHost::stopFaking();
});

it('accepts a host that resolves only to public addresses', function () {
    SafeRemoteHost::fake(['mastodon.social' => ['198.51.100.10']]);

    SafeRemoteHost::assertSafeHost('mastodon.social');
})->throwsNoExceptions();

it('accepts a literal public IPv4 address without DNS resolution', function () {
    SafeRemoteHost::fake([]); // faking with no entries proves resolveIps() short-circuits on literal IPs

    SafeRemoteHost::assertSafeHost('203.0.113.5');
})->throwsNoExceptions();

it('rejects a host that cannot be resolved at all (fail closed)', function () {
    SafeRemoteHost::fake([]);

    expect(fn () => SafeRemoteHost::assertSafeHost('nowhere.invalid'))
        ->toThrow(UnsafeRemoteHostException::class);
});

dataset('unsafe addresses', [
    'IPv4 loopback' => ['127.0.0.1'],
    'IPv4 loopback (localhost-style)' => ['127.1.2.3'],
    'IPv4 private (RFC1918 10/8)' => ['10.0.0.5'],
    'IPv4 private (RFC1918 172.16/12)' => ['172.16.5.5'],
    'IPv4 private (RFC1918 192.168/16)' => ['192.168.1.1'],
    'IPv4 link-local (cloud metadata)' => ['169.254.169.254'],
    'IPv4 multicast' => ['224.0.0.1'],
    'IPv4 unspecified' => ['0.0.0.0'],
    'IPv6 loopback' => ['::1'],
    'IPv6 link-local' => ['fe80::1'],
    'IPv6 unique-local' => ['fc00::1'],
]);

it('rejects a host resolving to an unsafe address', function (string $ip) {
    SafeRemoteHost::fake(['evil.internal' => [$ip]]);

    expect(fn () => SafeRemoteHost::assertSafeHost('evil.internal'))
        ->toThrow(UnsafeRemoteHostException::class);
})->with('unsafe addresses');

it('rejects a literal unsafe IP passed directly as the host, with no DNS involved', function (string $ip) {
    SafeRemoteHost::fake([]); // must not be consulted -- $ip is used as-is

    expect(fn () => SafeRemoteHost::assertSafeHost($ip))
        ->toThrow(UnsafeRemoteHostException::class);
})->with('unsafe addresses');

it('rejects a host that resolves to one safe and one unsafe address (any unsafe answer fails the whole host)', function () {
    SafeRemoteHost::fake(['mixed.example' => ['198.51.100.10', '169.254.169.254']]);

    expect(fn () => SafeRemoteHost::assertSafeHost('mixed.example'))
        ->toThrow(UnsafeRemoteHostException::class);
});

// --- assertSafeUrl(): scheme + host, in one call ------------------------

it('accepts an https URL whose host resolves publicly, returning the lowercased host', function () {
    SafeRemoteHost::fake(['mastodon.social' => ['198.51.100.10']]);

    $host = SafeRemoteHost::assertSafeUrl('https://Mastodon.Social/oauth/authorize?x=1');

    expect($host)->toBe('mastodon.social');
});

it('rejects a non-https scheme even when the host would resolve safely', function () {
    SafeRemoteHost::fake(['mastodon.social' => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeUrl('http://mastodon.social/oauth/authorize'))
        ->toThrow(UnsafeRemoteHostException::class);
});

it('rejects a URL whose host resolves to a private/metadata address', function () {
    SafeRemoteHost::fake(['evil.internal' => ['169.254.169.254']]);

    expect(fn () => SafeRemoteHost::assertSafeUrl('https://evil.internal/oauth/authorize'))
        ->toThrow(UnsafeRemoteHostException::class);
});

it('rejects a malformed URL', function () {
    expect(fn () => SafeRemoteHost::assertSafeUrl('not-a-url'))
        ->toThrow(UnsafeRemoteHostException::class);
});
