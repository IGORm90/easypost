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
 * tables. assertSafeUrl() additionally requires the `https` scheme.
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
 * Residual risk (stated plainly, not implied away): this validates the
 * DNS answer at the moment of the check, then a SEPARATE later HTTP
 * request resolves the same hostname again to actually connect -- a
 * classic TOCTOU/DNS-rebinding window if the attacker controls
 * authoritative DNS for the hostname and can flip the answer between
 * those two lookups. Fully closing that requires resolving once and
 * pinning the validated IP into the actual outbound connection (e.g. a
 * Guzzle `curl.resolve`/custom DNS resolver), which this class does not
 * do. What it DOES close: an admin directly typing a private/loopback/
 * metadata address or hostname, and a previously-safe host whose DNS
 * record was updated to point somewhere unsafe since the channel was
 * connected (caught on next use, not just at connect time).
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
     * Validates a full URL: scheme must be `https`, and the host must
     * pass assertSafeHost(). Returns the (lowercased) host on success so
     * callers don't have to re-parse the URL themselves.
     */
    public static function assertSafeUrl(string $url): string
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

        if (strtolower($parts['scheme']) !== 'https') {
            throw UnsafeRemoteHostException::forScheme($parts['scheme']);
        }

        $host = strtolower($parts['host']);
        self::assertSafeHost($host);

        return $host;
    }

    /**
     * Validates a bare hostname (or literal IP): every address it
     * resolves to must be a public, routable address. Throws
     * UnsafeRemoteHostException if the host can't be resolved at all
     * (fail closed -- an unresolvable host is not "safe by default", it
     * is unusable either way) or if any resolved address falls in a
     * private/loopback/link-local/reserved/multicast range.
     */
    public static function assertSafeHost(string $host): void
    {
        $ips = self::resolveIps($host);

        if ($ips === []) {
            throw UnsafeRemoteHostException::forHost($host, 'could not be resolved to any address');
        }

        foreach ($ips as $ip) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($public === false || self::isMulticast($ip)) {
                throw UnsafeRemoteHostException::forHost($host, "resolves to a non-public address [{$ip}]");
            }
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
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        // A literal IP needs no resolution regardless of whether DNS is
        // faked for this test -- checked first so fake()'s map only ever
        // has to cover hostnames, never literal-IP edge cases.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
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
