<?php

/*
 * Laravel's global TrustProxies middleware reads `trustedproxy.proxies`; there
 * is no config file for it in this skeleton, so without this one nothing is
 * ever trusted.
 *
 * Production: Caddy terminates TLS and forwards to nginx, which hands the
 * request to php-fpm with the original headers, so php sees the Caddy
 * container's address as REMOTE_ADDR. Set TRUSTED_PROXIES to the Compose
 * network's subnet that Caddy lives on (a comma-separated list of IPs/CIDRs).
 *
 * Unset (local dev, tests) means "trust nobody". Never `*`: with it, anyone who
 * can reach php-fpm directly can forge X-Forwarded-For and walk around the
 * per-IP login throttle, or forge X-Forwarded-Proto to get "secure" behaviour
 * over plain HTTP. Pinned by tests/Unit/Support/TrustedProxiesTest.php.
 */
return [
    'proxies' => env('TRUSTED_PROXIES'),
];
