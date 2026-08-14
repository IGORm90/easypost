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

/*
 * Re-review finding 5: the numeric-host check is byte-wise, so it has to
 * run on a NORMALISED host or it is trivially sidestepped. `０１７７.0.0.1`
 * (fullwidth digits) is not byte-wise numeric, and this container's
 * resolver applies UTS-46 and answers the public `177.0.0.1` — verified
 * directly, and verified that the pre-fix guard ACCEPTED it. Pinning
 * would not have saved it either: cURL keys its resolve cache on the
 * A-label, so a pin keyed on the U-label never matches.
 *
 * These fake DNS to a PUBLIC address for the same reason the rest of this
 * file does: the refusal must come from the guard, never from how a
 * resolver happens to answer.
 */
it('refuses a non-ASCII host rather than letting a byte-wise check run on it', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeHost($host))
        ->toThrow(UnsafeRemoteHostException::class);
})->with([
    '０１７７.0.0.1',   // fullwidth digits, UTS-46 maps to 0177.0.0.1 — then refused as ambiguous
    '１２７.0.0.1',
]);

it('converts an ordinary IDN host to its A-label and validates THAT', function () {
    // Re-review round 2, finding 4: `intl` the extension is absent, but
    // symfony/polyfill-intl-idn is a committed dependency and defines
    // idn_to_ascii under the app autoloader — so this branch really
    // converts in production, and an earlier claim that it always failed
    // closed was wrong. The A-label is what gets resolved, returned and
    // pinned; the U-label form is never handed to anything downstream.
    SafeRemoteHost::fake(['xn--exmple-cua.test' => ['198.51.100.10']]);

    [$host, $options] = SafeRemoteHost::assertSafeHostPinned('exämple.test');

    expect($host)->toBe('xn--exmple-cua.test')
        ->and($options)->toBe(['198.51.100.10'])
        ->and(SafeRemoteHost::pinOptions($host, $options)['curl'][CURLOPT_RESOLVE])
        ->toBe(['xn--exmple-cua.test:443:198.51.100.10']);
});

/*
 * Re-review round 2, finding 5: printable-ASCII was not a hostname
 * charset. Each of these was ACCEPTED by the guard and stopped only by
 * the resolver declining to answer — the exact accident this batch was
 * told not to rely on. `0177.0.0.1 ` is the one that reaches furthest:
 * the trailing space makes `"1 "` a non-numeric label, so the
 * ambiguous-literal check passes it.
 */
it('refuses anything that is not a hostname, whatever DNS would say', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeHost($host))
        ->toThrow(UnsafeRemoteHostException::class);
})->with([
    'evil.com/x',
    'evil.com#a.internal',
    'evil.com:8080',
    'evil.com?a=b',
    '0177.0.0.1 ',
    ' 0177.0.0.1',
    "127.0.0.1\t",
    'evil.com/../internal',
    'under_score.example.com',
]);

/*
 * Bracketed IPv6 literals had no branch at all: `[::1]` passed every
 * check here and was refused only because dns_get_record() answers
 * nothing for it. Now it is recognised as the literal it is — which both
 * closes the fail-by-luck and makes an IPv6-literal URL fetchable when
 * the address is genuinely public.
 */
it('unwraps a bracketed IPv6 literal and judges the address itself', function () {
    SafeRemoteHost::fake([]);

    expect(fn () => SafeRemoteHost::assertSafeHost('[::1]'))
        ->toThrow(UnsafeRemoteHostException::class, 'non-public address [::1]');
});

it('accepts a bracketed public IPv6 literal, keeping the URL-safe form', function () {
    SafeRemoteHost::fake([]);

    [$host, $ips] = SafeRemoteHost::assertSafeHostPinned('[2001:4860:4860::8888]');

    expect($host)->toBe('[2001:4860:4860::8888]')
        ->and($ips)->toBe(['2001:4860:4860::8888'])
        // A literal needs no pin: there was never a lookup to disagree with.
        ->and(SafeRemoteHost::pinOptions($host, $ips))->toBe([]);
});

it('refuses a malformed bracketed host', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeHost($host))
        ->toThrow(UnsafeRemoteHostException::class);
})->with(['[not-an-address]', '[127.0.0.1]', '[]']);

it('refuses a percent-encoded host outright, whatever the resolver would make of it', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeHost($host))
        ->toThrow(UnsafeRemoteHostException::class);
})->with(['%30%31%37%37.0.0.1', 'exa%6dple.com', '%2e%2e']);

it('normalises the host it returns, so a caller pins and connects to the same string', function () {
    SafeRemoteHost::fake(['mastodon.social' => ['198.51.100.10']]);

    [$host, $options] = SafeRemoteHost::assertSafeUrlPinned('https://MASTODON.Social/api/v1/apps');

    expect($host)->toBe('mastodon.social')
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['mastodon.social:443:198.51.100.10']);
});

/*
 * Re-review round 3, finding 1: PHP's `$` matches before a FINAL NEWLINE
 * unless the pattern carries the `D` modifier — so the charset allow-list
 * this batch added to close finding 5 was itself bypassed by a single
 * trailing "\n", and the guard returned `evil.com\n` verbatim as the host
 * to pin and to rebuild URLs from. Unexploitable today only because
 * dns_get_record() answers nothing for it and cURL rejects the URL: the
 * exact "refused by accident" pattern the allow-list existed to end.
 *
 * DNS is faked to a PUBLIC address for every case, so nothing here can
 * pass because a resolver declined to answer.
 */
it('refuses a host with a trailing newline, which PHP\'s $ anchor would otherwise admit', function (string $host) {
    SafeRemoteHost::fake([$host => ['198.51.100.10']]);

    expect(fn () => SafeRemoteHost::assertSafeHost($host))
        ->toThrow(UnsafeRemoteHostException::class);
})->with([
    "evil.com\n",
    "0177.0.0.1\n",     // the ambiguous-literal label check has the same quirk
    "[::1]\n",          // and so does the bracketed-literal match
    "evil.com\n.internal",
    "evil.com\r\n",
]);

/*
 * Re-review round 3, finding 2: IPv6 transition mechanisms carry an IPv4
 * destination inside an IPv6 address. PHP's NO_PRIV_RANGE|NO_RES_RANGE
 * decodes the IPv4-MAPPED form and rejects it, which is easy to mistake
 * for "embedded IPv4 is handled" — 6to4, NAT64 and Teredo all passed as
 * ordinary public IPv6. The AAAA path had this already; bracketed
 * literals made it directly reachable.
 *
 * `2002:a9fe:a9fe::` is 169.254.169.254 in 6to4 — the cloud-metadata
 * address this entire batch is about.
 */
it('refuses an IPv6 transition address whose embedded IPv4 is not public, as a literal', function (string $host) {
    SafeRemoteHost::fake([]);

    expect(fn () => SafeRemoteHost::assertSafeHost($host))
        ->toThrow(UnsafeRemoteHostException::class);
})->with([
    '[2002:a9fe:a9fe::]',      // 6to4 of 169.254.169.254 (cloud metadata)
    '[2002:7f00:1::]',         // 6to4 of 127.0.0.1
    '[2002:c0a8:1::]',         // 6to4 of 192.168.0.1
    '[2002:6440:1::]',         // 6to4 of 100.64.0.1 (CGNAT)
    '[64:ff9b::7f00:1]',       // NAT64 of 127.0.0.1
    '[64:ff9b::a9fe:a9fe]',    // NAT64 of 169.254.169.254
    // NAT64 local-use prefix with RFC 6052 §2.2's /48 embedding — IPv4
    // at bytes 6-7 and 9-10, NOT the last four (re-review round 4: the
    // old fixture here, '[64:ff9b:1::7f00:1]', was not the RFC form and
    // decoded to 0.0.0.0, so it proved nothing about /48 handling).
    '[64:ff9b:1:7f00:0:100::]',    // /48 form of 127.0.0.1
    '[64:ff9b:1:a9fe:a9:fe00::]',  // /48 form of 169.254.169.254
    '[64:ff9b:1:c0a8:0:100::]',    // /48 form of 192.168.0.1
    '[64:ff9b:1:808:100:800::]',   // 8.8.8.8 but with a non-zero reserved `u` octet — not a valid embedding
    '[64:ff9b:2::808:808]',        // a shape under 64:ff9b::/32 with no defined embedding
    '[2001:0:1::]',            // Teredo, refused outright
    '[2001::]',
]);

it('refuses an IPv6 transition address arriving as a DNS answer, not just as a literal', function (string $ip) {
    // The gap this closes predates the literal support: a hostname whose
    // AAAA record is a 6to4/NAT64 metadata address was equally accepted.
    SafeRemoteHost::fake(['tunnel.example' => [$ip]]);

    expect(fn () => SafeRemoteHost::assertSafeHost('tunnel.example'))
        ->toThrow(UnsafeRemoteHostException::class);
})->with(['2002:a9fe:a9fe::', '64:ff9b::a9fe:a9fe', '2001:0:1::']);

it('still accepts a transition address whose embedded IPv4 is genuinely public', function (string $host) {
    // Decoded, not blanket-rejected: the embedded address is the whole
    // meaning of these prefixes, so the check is stricter where it
    // matters and honest where it does not.
    SafeRemoteHost::fake([]);

    SafeRemoteHost::assertSafeHost($host);
})->with([
    '[2002:0808:0808::]',      // 6to4 of 8.8.8.8
    '[64:ff9b::808:808]',      // NAT64 well-known prefix, 8.8.8.8
    '[64:ff9b:1:808:8:800::]', // NAT64 local-use /48 prefix, 8.8.8.8 — decoded, not blanket-rejected
    '[2001:4860:4860::8888]',  // an ordinary public IPv6 address
])->throwsNoExceptions();

it('still rejects the IPv4-mapped form PHP already handled, so the new checks did not replace it', function () {
    SafeRemoteHost::fake([]);

    expect(fn () => SafeRemoteHost::assertSafeHost('[::ffff:127.0.0.1]'))
        ->toThrow(UnsafeRemoteHostException::class);
});
