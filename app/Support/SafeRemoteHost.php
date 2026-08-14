<?php

namespace App\Support;

use App\Support\Exceptions\UnsafeRemoteHostException;

/**
 * SSRF guard for any feature that makes a server-side HTTP request to a
 * host supplied (directly or indirectly) by a user: today, the Mastodon
 * instance host an org admin types in when connecting a channel; Task 27
 * ("upload media by URL") will need exactly the same check. Built here
 * as shared infrastructure rather than a private method on
 * MastodonProvider so the second caller doesn't have to reinvent it (or
 * worse, half-remember to).
 *
 * What it rejects: resolves the host (or accepts it directly if it's
 * already a literal IP) and rejects it if ANY resolved address is
 * loopback, RFC1918/ULA private, link-local (including the
 * 169.254.169.254 cloud-metadata address), multicast, or otherwise
 * reserved -- for both IPv4 and IPv6, via PHP's own
 * FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE, which cover
 * these categories without this class having to hand-maintain CIDR
 * tables, plus RFC 6598 CGNAT space and IPv4/IPv6 multicast (neither is
 * covered by those flags — see isCgnat()/isMulticast()). assertSafeUrl()
 * additionally requires the scheme to be in its (by default `['https']`)
 * allow-list and rejects any URL carrying userinfo — see that method's
 * own docblock.
 *
 * Two call sites, deliberately: assertSafeHost()/assertSafeUrl() must be
 * called both when a host is first accepted from a user (so an admin
 * can't aim a connect flow at an internal service in the first place)
 * AND again every time a previously-validated, now-stored host is about
 * to be used (so a host that resolved publicly at connect time but now
 * resolves privately -- DNS rebinding, or an internal DNS record that
 * simply changed -- is still caught before the request goes out). A
 * check that only runs once, at connect time, is a speed bump, not a
 * guard.
 *
 * ## Two parsers, one host: why assertNotAmbiguousLiteral() exists
 *
 * Final-review batch 2, Critical 1 -- proven live, not inferred: an
 * octal-dotted host such as `0177.0.0.1` is REJECTED by
 * `filter_var($host, FILTER_VALIDATE_IP)` (a leading zero is not a valid
 * decimal quad), so it used to fall through to `dns_get_record()`, which
 * this project's resolver answers with `177.0.0.1` -- a public address
 * the guard then happily approved. cURL, meanwhile, parses the ORIGINAL
 * host string with its own inet_aton logic and connects to `127.0.0.1`.
 * The reviewer retrieved a loopback server's response body through
 * `MediaUploadService::download()` this way; so did this batch, before
 * the fix (see the report). Every relative of that trick -- `0x7f.0.0.1`,
 * `127.1`, `017700000001`, `2130706433` -- is the same bug; they were
 * only "blocked" on the reviewer's machine because that resolver
 * happened to answer NXDOMAIN, which is a property of a resolver, not of
 * this guard.
 *
 * Two independent closures, because either alone leaves a gap:
 *
 *  1. assertNotAmbiguousLiteral() refuses, BEFORE DNS is consulted, any
 *     host whose labels are ALL numeric/hex/octal -- i.e. anything cURL
 *     (or any other inet_aton-style parser) could still read as an IP
 *     literal even though FILTER_VALIDATE_IP would not. Such a string is
 *     never a legitimate hostname anyway: no public TLD is all-digits.
 *  2. pinning (assertSafeUrlPinned()/pinOptions()) removes the second
 *     parser from the picture entirely: the caller hands cURL the exact
 *     IP this class validated, via CURLOPT_RESOLVE, so the address that
 *     was checked IS the address connected to. This also closes the
 *     TOCTOU/DNS-rebinding window described below and the "redirect to a
 *     host nobody validated" hop, since a pinned request cannot be
 *     re-pointed by a second lookup.
 *
 * Residual risk (stated plainly, not implied away): assertSafeHost() on
 * its own still validates the DNS answer at the moment of the check,
 * and a caller that then issues an ordinary HTTP request by hostname
 * resolves it a SECOND time to actually connect -- a classic
 * TOCTOU/DNS-rebinding window if the attacker controls authoritative DNS
 * for the hostname and can flip the answer between those two lookups.
 * That is exactly what pinning closes, and every caller in this codebase
 * that resolves a host through this class now pins — and each one is
 * covered by a test that asserts the pin is present in the options of the
 * request it actually issues (tests/Feature/Media/PinnedRequestTest.php),
 * because "the class produces a pin" and "the call site sends it" are two
 * different claims and only the second one matters:
 *
 *   - MediaUploadService::download() — every hop of the redirect chain.
 *   - MastodonProvider — the user-supplied instance host, connect-time and
 *     every publish.
 *   - DiscordProvider — the stored webhook url.
 *   - SlackProvider — the upload url Slack's own API returns.
 *
 * The four networks with FIXED API hosts (api.telegram.org, bsky.social,
 * discord.com's and slack.com's API legs) deliberately do NOT pin: they
 * never resolve through this class in the first place, so pinning them
 * would mean ADDING a mandatory DNS lookup — a new failure mode for hosts
 * no caller can influence. Their redirect exposure is closed by
 * BoundsHttpTimeouts instead.
 *
 * A caller that only calls assertSafeHost() and does not pin still
 * carries the window; that is a property of the CALL SITE, not of this
 * class.
 */
final class SafeRemoteHost
{
    /**
     * Test-only override: when set, resolveIps() returns from this map
     * instead of touching real DNS -- mirrors this codebase's
     * Http::fake()-style testing convention (see also
     * App\Support\OrganizationContext's set()/clear() for the same
     * static-test-seam pattern). Callers must call stopFaking() when
     * done (Pest's afterEach(), typically) so state doesn't leak
     * between tests.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $fakeResolutions = null;

    /**
     * @param  array<string, list<string>>  $hostToIps  Hostname (lowercase, no scheme) => resolved IP addresses.
     */
    public static function fake(array $hostToIps): void
    {
        self::$fakeResolutions = $hostToIps;
    }

    public static function stopFaking(): void
    {
        self::$fakeResolutions = null;
    }

    /**
     * Validates a full URL: scheme must be in $allowedSchemes (default
     * `['https']`, preserving every pre-Task-27 caller's behaviour
     * unchanged -- Mastodon's/Discord's URLs are always built or stored
     * as https), the URL must carry no userinfo, and the host must pass
     * assertSafeHost(). Returns the (lowercased) host on success so
     * callers don't have to re-parse the URL themselves.
     *
     * $allowedSchemes exists because Task 27 ("upload media by URL") is
     * the first caller handed a whole, untrusted, user-supplied URL
     * (every prior caller only ever validated a bare, already-typed-in
     * host, or a URL this codebase itself assembled/stored) -- and it
     * must accept plain `http` as well as `https`, which the previous
     * hardcoded https-only check could not express without weakening it
     * for every existing caller too.
     *
     * Userinfo rejection (`user@host` / `user:pass@host`) is Task 27
     * review groundwork, not scheme-specific, so it applies unconditionally
     * for every caller: parse_url() and this codebase's own resolution
     * logic agree that the HOST component (not the userinfo) is what a
     * request actually connects to, so `https://trusted.com@127.0.0.1/`
     * was already being correctly rejected via its host (127.0.0.1) even
     * before this check existed. It is added anyway as defense in depth
     * against a *different* class of risk this method cannot rule out by
     * construction: a parser-differential bypass, where some other URL
     * parser in the request path (a proxy, a future HTTP client
     * configuration, a different PHP/cURL version) disagrees with
     * PHP's parse_url() about which component is the "real" host once
     * userinfo is present. Userinfo has no legitimate use for any
     * caller of this method (no request this application makes needs
     * embedded Basic-Auth-style credentials in the URL itself), so the
     * simplest closure is to refuse it outright rather than trust two
     * different parsers to keep agreeing forever.
     *
     * @param  list<string>  $allowedSchemes
     */
    public static function assertSafeUrl(string $url, array $allowedSchemes = ['https']): string
    {
        return self::assertSafeUrlPinned($url, $allowedSchemes)[0];
    }

    /**
     * assertSafeUrl(), plus the Guzzle options that PIN the connection to
     * the addresses this call just validated -- final-review batch 2,
     * Critical 1/Important 6. Returns `[host, options]`; the caller must
     * pass those options to the request it is about to make
     * (`->withOptions($options)`), which is what makes the address that
     * was checked the address actually connected to.
     *
     * Resolution happens exactly ONCE, inside the assertSafeHost() call
     * below, and the very same list is what gets pinned -- a second
     * lookup here would reintroduce precisely the TOCTOU window pinning
     * exists to close (validate answer A, connect to answer B).
     *
     * @param  list<string>  $allowedSchemes
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function assertSafeUrlPinned(string $url, array $allowedSchemes = ['https']): array
    {
        $parts = parse_url($url);

        // Deliberately does NOT embed $url in the exception (review
        // finding on Task 15): unlike Mastodon's bare instance host (the
        // only caller when this method was first written), Discord's
        // webhook url IS the credential -- a malformed/tampered stored
        // value could still contain it verbatim even though it fails to
        // parse as a URL at all, and this is the one branch here that
        // had direct access to the raw, unparsed input rather than an
        // already-extracted host/scheme. Every other branch below only
        // ever embeds a value parse_url() or DNS resolution already
        // pulled apart (a bare host, a scheme, a resolved IP) -- never
        // the original credential-bearing string.
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw UnsafeRemoteHostException::forMalformedUrl();
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeRemoteHostException::forMalformedUrl();
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, array_map('strtolower', $allowedSchemes), true)) {
            throw UnsafeRemoteHostException::forScheme($parts['scheme']);
        }

        // The NORMALISED host is what is returned and what the pin is
        // keyed on — never the raw one from parse_url() (re-review
        // finding 5): a caller that rebuilds a URL from this value, or
        // pins it, must be using the same string the resolver and cURL
        // will use.
        [$host, $ips] = self::assertSafeHostPinned($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'http' ? 80 : 443);

        return [$host, self::pinOptions($host, $ips, $port)];
    }

    /**
     * Guzzle/cURL options pinning $host:$port to exactly $ips, so the
     * outbound connection cannot land anywhere other than the addresses
     * assertSafeHost() approved. `CURLOPT_RESOLVE` is a pre-populated
     * DNS cache entry for that host/port pair: cURL uses it instead of
     * resolving, so there is no second lookup for a rebinding attacker to
     * win and no second parser to disagree about what the host string
     * means.
     *
     * A literal-IP host needs no entry (there was nothing to resolve),
     * and an empty $ips list cannot happen for a host that passed
     * assertSafeHost() -- both are returned as "no pinning" rather than
     * as a malformed option cURL would reject.
     *
     * Inert under `Http::fake()` (no cURL handler runs), which is why the
     * tests that matter for this defect drive real cURL -- see
     * tests/Feature/Media/ImportFromUrlSsrfTest.php.
     *
     * @param  list<string>  $ips
     * @return array<string, mixed>
     */
    public static function pinOptions(string $host, array $ips, int $port = 443): array
    {
        if ($ips === [] || self::literalIp($host) !== null) {
            return [];
        }

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $ips)]]];
    }

    /**
     * Validates a bare hostname (or literal IP): every address it
     * resolves to must be a public, routable address. Throws
     * UnsafeRemoteHostException if the host can't be resolved at all
     * (fail closed -- an unresolvable host is not "safe by default", it
     * is unusable either way) or if any resolved address falls in a
     * private/loopback/link-local/reserved/multicast range.
     *
     * Returns the validated addresses so a caller can PIN them into the
     * outbound request (see pinOptions()) instead of letting the HTTP
     * client resolve the name a second time -- final-review batch 2.
     *
     * @return list<string>
     */
    public static function assertSafeHost(string $host): array
    {
        return self::assertSafeHostPinned($host)[1];
    }

    /**
     * assertSafeHost(), returning the NORMALISED host (the form the
     * resolver and the HTTP client will both actually use) alongside the
     * validated addresses — re-review finding 5. A caller that builds a
     * URL or a pin from the host MUST use the returned value, not the one
     * it passed in: `pinOptions()` keys on the exact host string cURL
     * looks up, so pinning a form cURL never sees pins nothing at all.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function assertSafeHostPinned(string $host): array
    {
        $host = self::normalizeHost($host);

        self::assertNotAmbiguousLiteral($host);

        $ips = self::resolveIps($host);

        if ($ips === []) {
            throw UnsafeRemoteHostException::forHost($host, 'could not be resolved to any address');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicAddress($ip)) {
                throw UnsafeRemoteHostException::forHost($host, "resolves to a non-public address [{$ip}]");
            }
        }

        return [$host, $ips];
    }

    /**
     * The single verdict on one address, so every caller (the AAAA/A
     * answers above, and the recursive check on an IPv6 transition
     * address's embedded IPv4 below) applies exactly the same bar.
     */
    private static function isPublicAddress(string $ip): bool
    {
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false || self::isMulticast($ip) || self::isCgnat($ip)) {
            return false;
        }

        return ! self::isUnsafeTransitionAddress($ip);
    }

    /**
     * IPv6 transition mechanisms that carry an IPv4 destination inside an
     * IPv6 address — re-review round 3.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` decodes the
     * IPv4-MAPPED form (`::ffff:127.0.0.1`) and rejects it, which is
     * easy to mistake for "PHP handles embedded IPv4". It does not handle
     * the others: 6to4, NAT64 and Teredo all pass as ordinary public
     * IPv6. That gap existed on the AAAA path already, and the bracketed
     * IPv6 literal support added last round made it reachable directly —
     * verified against the guard, `[64:ff9b::7f00:1]` and
     * `[2002:7f00:1::]` were ACCEPTED, and the reviewer saw cURL attempt
     * the connection. `2002:a9fe:a9fe::` is the 6to4 encoding of
     * 169.254.169.254 — the cloud-metadata address this whole batch is
     * about — and it was accepted too.
     *
     *  - 6to4 (`2002::/16`, RFC 3056): bytes 2-5 ARE the IPv4
     *    destination. Decoded and judged by the same isPublicAddress()
     *    bar, so `2002:0808:0808::` (8.8.8.8) still works while
     *    `2002:7f00:1::` (127.0.0.1) does not. Not blanket-rejected: the
     *    embedded address is the whole meaning of the prefix, so checking
     *    it is both stricter where it matters and honest where it does
     *    not.
     *  - NAT64 (`64:ff9b::/96` and the local-use `64:ff9b:1::/48`, RFCs
     *    6052/8215): the last four bytes are the IPv4 destination. Same
     *    treatment.
     *  - Teredo (`2001::/32`, RFC 4380): rejected outright rather than
     *    decoded. Its embedded server and client IPv4 addresses are
     *    obfuscated (the client address is stored XOR'd) and the tunnel's
     *    actual destination depends on the relay, so there is no single
     *    address to judge — and no legitimate reason for this application
     *    to fetch a customer's media over Teredo.
     */
    private static function isUnsafeTransitionAddress(string $ip): bool
    {
        if (! str_contains($ip, ':')) {
            return false;
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        // Teredo: 2001:0000::/32.
        if (substr($packed, 0, 4) === "\x20\x01\x00\x00") {
            return true;
        }

        // 6to4: 2002::/16 — the IPv4 destination is bytes 2-5.
        if (substr($packed, 0, 2) === "\x20\x02") {
            return ! self::isPublicAddress(self::ipv4From(substr($packed, 2, 4)));
        }

        // NAT64: 64:ff9b::/96 and 64:ff9b:1::/48 — the IPv4 destination
        // is the last four bytes.
        if (substr($packed, 0, 4) === "\x00\x64\xff\x9b") {
            return ! self::isPublicAddress(self::ipv4From(substr($packed, 12, 4)));
        }

        return false;
    }

    /**
     * Four packed bytes as a dotted-quad string.
     */
    private static function ipv4From(string $packed): string
    {
        $address = @inet_ntop($packed);

        // inet_ntop() only fails on a wrong-length input, which cannot
        // happen from this class's own fixed substr() offsets — treated
        // as unsafe rather than assumed away, since the caller reads the
        // answer as an address to judge.
        return is_string($address) ? $address : '127.0.0.1';
    }

    /**
     * Puts a host into the single form everything downstream agrees on —
     * lowercase ASCII, no percent escapes — or refuses it. Re-review
     * finding 5, and the reason it is a REAL hole rather than a tidy-up:
     *
     * `assertNotAmbiguousLiteral()` compares bytes, and it used to run on
     * the raw string. `０１７７.0.0.1` (fullwidth digits) is not
     * byte-wise numeric, so it sailed through — and then `dns_get_record()`
     * applied UTS-46, resolved the ASCII form, and answered the public
     * `177.0.0.1`, so the guard APPROVED it. Pinning did not save it
     * either: cURL keys its resolve cache on the A-label, while the pin
     * would have been keyed on the U-label, so the entry simply never
     * matched and the connection resolved normally. Percent-encoded
     * numeric labels (`%30%31%37%37.0.0.1`) are the same shape, refused
     * today only by NXDOMAIN — the resolver accident this batch was told
     * not to lean on. Both were stopped end to end by Guzzle's own
     * `HostValidator`, i.e. by a dependency's behaviour, one client
     * change away from being open.
     *
     * So: percent escapes are refused outright (no caller needs them in a
     * host), and a non-ASCII host is converted to its A-label via UTS-46
     * before ANY check runs.
     *
     * On `idn_to_ascii` availability, corrected (re-review round 2,
     * finding 4 — an earlier revision of this docblock and of the batch
     * report both said `intl` was absent here and that the non-ASCII
     * branch therefore always failed closed): the `intl` EXTENSION is
     * indeed not loaded in this image, but `symfony/polyfill-intl-idn` is
     * a committed dependency and defines `idn_to_ascii` under the
     * application autoloader. So the conversion branch is the one that
     * actually runs in production, and it runs correctly — `０１７７.0.0.1`
     * normalises to `0177.0.0.1` and is then refused as an ambiguous
     * literal, and an ordinary IDN host resolves and is validated under
     * its A-label. The `function_exists()` fallback is kept for an
     * installation that somehow has neither, where refusing a host this
     * code cannot canonicalise is the only safe answer.
     *
     * The charset allow-list after conversion (re-review round 2, finding
     * 5) is what keeps this method's output an actual hostname. Printable
     * ASCII alone admitted `evil.com/x`, `evil.com#a.internal`,
     * `evil.com:8080`, and — the one that reaches further — `0177.0.0.1 `
     * with a trailing space, which defeats assertNotAmbiguousLiteral()
     * because `"1 "` is not a numeric label. None of those resolve today,
     * but "the resolver happens not to answer" is exactly the accident
     * this batch exists to stop relying on.
     *
     * Bracketed IPv6 literals (`[::1]`) are handled explicitly rather than
     * left to fall through the allow-list: before this, `[::1]` was
     * accepted by every check here and refused only because
     * `dns_get_record('[::1]')` answers nothing — fail-closed by luck, and
     * it also meant no IPv6-literal URL could ever be fetched at all. The
     * brackets are kept in the returned host (a URL rebuilt from it must
     * stay valid) while literalIp() unwraps them for the checks that care
     * about the address itself.
     */
    private static function normalizeHost(string $host): string
    {
        if (str_contains($host, '%')) {
            throw UnsafeRemoteHostException::forHost($host, 'contains percent-encoding, which this guard and an HTTP client would read differently');
        }

        if (preg_match('/^[\x20-\x7E]*$/D', $host) !== 1) {
            if (! function_exists('idn_to_ascii')) {
                throw UnsafeRemoteHostException::forHost($host, 'is a non-ASCII host and this installation cannot convert it to its ASCII form');
            }

            /** @var string|false $ascii */
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($ascii) || $ascii === '') {
                throw UnsafeRemoteHostException::forHost($host, 'could not be converted to a valid ASCII hostname');
            }

            $host = $ascii;
        }

        $host = strtolower($host);

        if (preg_match('/^\[(.+)]$/D', $host, $matches) === 1) {
            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw UnsafeRemoteHostException::forHost($host, 'is not a well-formed IPv6 literal');
            }

            return $host;
        }

        if (preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            throw UnsafeRemoteHostException::forHost($host, 'is not a well-formed hostname');
        }

        return $host;
    }

    /**
     * The bare IP address $host denotes, or null if it is a name. Handles
     * the bracketed IPv6 URL form (`[::1]`) as well as a plain literal, so
     * every check that asks "is this already an address?" gets the same
     * answer for both spellings — re-review round 2. Before this, the
     * bracketed form was not recognised anywhere: it skipped the literal
     * short-circuit, was handed to DNS, and survived on the resolver
     * answering nothing.
     */
    private static function literalIp(string $host): ?string
    {
        if (preg_match('/^\[(.+)]$/D', $host, $matches) === 1) {
            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? $matches[1]
                : null;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
    }

    /**
     * Final-review batch 2, Critical 1 -- the pre-DNS half of the fix
     * (see this class's docblock for the proven bypass this closes).
     *
     * Rejects any host that FILTER_VALIDATE_IP does NOT accept but that
     * an inet_aton-style parser (cURL's, among others) still would: a
     * dotted string whose every label is decimal, octal (`0177`) or hex
     * (`0x7f`), including the short forms with fewer than four labels
     * (`127.1`) and the bare-integer form (`2130706433`). For all of
     * these, this class's check and cURL's connection would be looking at
     * two different addresses -- so the string is refused outright rather
     * than resolved and approved on the strength of whatever the local
     * resolver happens to make of it. Nothing legitimate is lost: no
     * public hostname's rightmost label is all-numeric, and a genuine IP
     * literal (which FILTER_VALIDATE_IP accepts) never reaches here.
     *
     * Also rejects a trailing dot (`example.com.`) and any empty label:
     * a second, smaller place where this class's notion of a host and a
     * resolver's differ, with no legitimate caller behind it.
     */
    private static function assertNotAmbiguousLiteral(string $host): void
    {
        if (self::literalIp($host) !== null) {
            return;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if ($label === '') {
                throw UnsafeRemoteHostException::forHost($host, 'is not a well-formed hostname');
            }
        }

        $numericLabels = array_filter(
            $labels,
            static fn (string $label): bool => preg_match('/^(0[xX][0-9a-fA-F]+|[0-9]+)$/D', $label) === 1,
        );

        if (count($numericLabels) === count($labels)) {
            throw UnsafeRemoteHostException::forHost(
                $host,
                'is an ambiguous numeric host that an HTTP client could read as an IP literal',
            );
        }
    }

    /**
     * Defense in depth beyond FILTER_FLAG_NO_RES_RANGE: verified
     * empirically (not merely assumed from the docs) that it does NOT
     * reliably exclude IPv4 multicast (224.0.0.0/4) or IPv6 multicast
     * (ff00::/8) -- e.g. `filter_var('224.0.0.1', FILTER_VALIDATE_IP,
     * FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` returns the
     * address unchanged (i.e. "valid"/public) on this project's PHP
     * build, even though multicast is not a legitimate target for a
     * unicast HTTP request. Checked explicitly rather than trusted to
     * the built-in flags.
     */
    private static function isMulticast(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);

            return $packed !== false && (ord($packed[0]) & 0xFF) === 0xFF; // ff00::/8
        }

        $long = ip2long($ip);

        return $long !== false && ($long & 0xF0000000) === 0xE0000000; // 224.0.0.0/4
    }

    /**
     * Task 27 review, gap 1: RFC 6598 shared/CGNAT space (100.64.0.0/10)
     * is neither RFC1918-private nor in PHP's "reserved" list, so
     * FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE passes it as
     * "public" -- verified empirically the same way isMulticast() was:
     * `filter_var('100.64.0.5', ..., FILTER_FLAG_NO_PRIV_RANGE |
     * FILTER_FLAG_NO_RES_RANGE)` returns the address unchanged on this
     * project's PHP build. This range is carrier-grade NAT space, used
     * internally by ISPs/cloud providers to address infrastructure not
     * meant to be reachable from the public Internet (some cloud
     * metadata-adjacent services live here) -- not a legitimate target
     * for a server-side fetch this application makes on a user's behalf,
     * same bar as RFC1918. No IPv6 equivalent exists (there is no IPv6
     * CGNAT range), so this only ever runs for IPv4 addresses.
     */
    private static function isCgnat(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            return false;
        }

        $long = ip2long($ip);

        // 100.64.0.0/10 == 100.64.0.0 - 100.127.255.255.
        return $long !== false && ($long & 0xFFC00000) === 0x64400000;
    }

    /**
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        // A literal IP needs no resolution regardless of whether DNS is
        // faked for this test -- checked first so fake()'s map only ever
        // has to cover hostnames, never literal-IP edge cases.
        $literal = self::literalIp($host);

        if ($literal !== null) {
            return [$literal];
        }

        if (self::$fakeResolutions !== null) {
            return self::$fakeResolutions[$host] ?? [];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }

            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
