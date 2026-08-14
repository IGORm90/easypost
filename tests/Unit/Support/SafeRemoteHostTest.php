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
    // Task 27 review, gap 1: RFC 6598 shared/CGNAT space was previously
    // unblocked -- FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    // does not cover it (verified empirically, see isCgnat()'s
    // docblock).
    'IPv4 CGNAT (RFC6598 100.64/10, first address)' => ['100.64.0.0'],
    'IPv4 CGNAT (RFC6598 100.64/10, mid-range)' => ['100.100.100.100'],
    'IPv4 CGNAT (RFC6598 100.64/10, last address)' => ['100.127.255.255'],
    // Task 27 review, gap 3: IPv6 multicast was previously verified only
    // by hand, with no test covering it -- ff00::/8 passes PHP's own
    // filter flags unchanged (same as IPv4 multicast above), so
    // isMulticast()'s IPv6 branch is the only thing rejecting it.
    'IPv6 multicast (all-nodes link-local)' => ['ff02::1'],
    'IPv6 multicast (ff00::/8 boundary)' => ['ff00::'],
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

// --- Task 15 review, Finding 2 (Important): a caller may feed a
// credential-bearing string through assertSafeUrl() (Discord's stored
// webhook url IS its credential). If that string is malformed enough
// that parse_url() can't extract a scheme/host from it, the raw input
// is all this method has left -- and must NOT echo it back, or a
// tampered/corrupted stored credential leaks into the exception message
// (and from there, into logs).

it('does not embed the raw input in the exception message for a malformed, credential-bearing URL', function () {
    $secret = 'super-secret-webhook-token-xyz789';

    try {
        SafeRemoteHost::assertSafeUrl("not a url at all {$secret}");
        test()->fail('Expected UnsafeRemoteHostException.');
    } catch (UnsafeRemoteHostException $e) {
        expect($e->getMessage())->not->toContain($secret);
    }
});

// --- Task 27 review, gap 1: CGNAT boundaries -----------------------------

it('accepts addresses immediately outside the CGNAT range', function (string $ip) {
    SafeRemoteHost::fake([]);

    SafeRemoteHost::assertSafeHost($ip);
})->with([
    'just below 100.64.0.0/10' => ['100.63.255.255'],
    'just above 100.64.0.0/10' => ['100.128.0.0'],
])->throwsNoExceptions();

// --- Task 27 review, gap 2: userinfo obfuscation -------------------------

it('rejects a URL carrying userinfo even when the host itself would resolve safely', function () {
    SafeRemoteHost::fake(['trusted.com' => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeUrl('https://evil-user@trusted.com/'))
        ->toThrow(UnsafeRemoteHostException::class);
});

it('rejects a URL whose userinfo is itself used to disguise the real (unsafe) host', function () {
    // parse_url()/curl agree the HOST is 127.0.0.1 here (everything
    // before '@' is userinfo) -- assertSafeHost() already rejects this
    // via the host check, but the explicit userinfo rejection above
    // closes it unconditionally, without relying on that agreement.
    SafeRemoteHost::fake([]);

    expect(fn () => SafeRemoteHost::assertSafeUrl('https://trusted.com@127.0.0.1/'))
        ->toThrow(UnsafeRemoteHostException::class);
});

// --- Task 27: $allowedSchemes ---------------------------------------------

it('accepts a plain http URL when http is explicitly allowed', function () {
    SafeRemoteHost::fake(['example.com' => ['198.51.100.10']]);

    $host = SafeRemoteHost::assertSafeUrl('http://example.com/file.jpg', ['http', 'https']);

    expect($host)->toBe('example.com');
});

it('still rejects a non-http(s) scheme even when http/https are both allowed', function () {
    SafeRemoteHost::fake(['example.com' => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeUrl('ftp://example.com/file.jpg', ['http', 'https']))
        ->toThrow(UnsafeRemoteHostException::class);
});

/*
 * Final-review batch 2, Critical 1 — the octal/hex/short-form IP literal
 * family, proven live against a loopback server through the real
 * MediaUploadService::download() before the fix.
 *
 * Every case below fakes DNS so the host resolves to a PUBLIC address.
 * That is the whole point: on the reviewer's machine (and in this
 * project's container) some of these were "blocked" only because the
 * local resolver answered NXDOMAIN, and `0177.0.0.1` was NOT — it
 * resolved to 177.0.0.1, a public address the guard approved, while cURL
 * connected to 127.0.0.1. A test that let the resolver decide would pass
 * against the broken code on one machine and fail on another. These
 * assert the guard refuses the STRING, whatever DNS says about it.
 */
it('rejects a numeric host an HTTP client could read as an IP literal, even when DNS says it is public', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeHost($host))
        ->toThrow(UnsafeRemoteHostException::class);
})->with([
    '0177.0.0.1',        // octal-dotted: resolves to 177.0.0.1, connects to 127.0.0.1
    '0x7f.0.0.1',        // hex label
    '127.1',             // short form
    '017700000001',      // single octal integer
    '2130706433',        // single decimal integer
    '0x7f000001',        // single hex integer
    '192.168.001.001',   // zero-padded private address
]);

it('rejects the same family through a full URL', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeUrl("http://{$host}/x.png", ['http', 'https']))
        ->toThrow(UnsafeRemoteHostException::class);
})->with(['0177.0.0.1', '0x7f.0.0.1', '127.1', '017700000001', '2130706433']);

it('still accepts an ordinary hostname whose labels are only partly numeric', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    SafeRemoteHost::assertSafeHost($host);
})->with(['123.example.com', 'mastodon.social', '0x7f.example.com', 'a1.b2.test'])->throwsNoExceptions();

it('rejects a trailing-dot host, where this guard and a resolver disagree about the name', function () {
    SafeRemoteHost::fake(['example.com.' => ['198.51.100.10'], 'example.com' => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeHost('example.com.'))
        ->toThrow(UnsafeRemoteHostException::class);
});

/*
 * Pinning (Critical 1's real closure, and Important 6's): the caller is
 * handed the exact addresses this class validated, in the form cURL uses
 * INSTEAD of resolving — so there is no second lookup and no second
 * parser. tests/Feature/Media/ImportFromUrlPinningTest.php proves the
 * option actually governs where real cURL connects; these prove the
 * value's shape and that it comes from the validated answer.
 */
it('returns pinning options carrying exactly the validated addresses', function () {
    SafeRemoteHost::fake(['mastodon.social' => ['198.51.100.10', '198.51.100.11']]);

    [$host, $options] = SafeRemoteHost::assertSafeUrlPinned('https://mastodon.social/api/v1/apps');

    expect($host)->toBe('mastodon.social')
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['mastodon.social:443:198.51.100.10,198.51.100.11']);
});

it('pins the URL\'s own port, not a default', function () {
    SafeRemoteHost::fake(['files.example.com' => ['198.51.100.10']]);

    [, $options] = SafeRemoteHost::assertSafeUrlPinned('http://files.example.com:8080/a.png', ['http', 'https']);

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['files.example.com:8080:198.51.100.10']);
});

it('pins nothing for a literal IP host (there was no resolution to pin)', function () {
    SafeRemoteHost::fake([]);

    [, $options] = SafeRemoteHost::assertSafeUrlPinned('https://203.0.113.5/x');

    expect($options)->toBe([]);
});

it('returns the validated addresses from assertSafeHost so a caller can pin them', function () {
    SafeRemoteHost::fake(['mastodon.social' => ['198.51.100.10']]);

    expect(SafeRemoteHost::assertSafeHost('mastodon.social'))->toBe(['198.51.100.10']);
});
