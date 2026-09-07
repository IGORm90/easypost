<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Support\SafeRemoteHost when a caller-supplied (or
 * previously-connected, since DNS answers change) remote host is not
 * safe to make a server-side request to: it resolves to a loopback,
 * private, link-local, unique-local, multicast or otherwise reserved
 * address, or the URL doesn't use `https`.
 *
 * Deliberately does NOT implement App\Social\Exceptions\ProviderException
 * -- this is an infrastructure-level guard shared by anything that makes
 * an outbound request to a user-supplied host ("upload media by URL"
 * today; any future provider that lets a customer name its host), not a
 * live-provider-call outcome. Callers inside app/Social/Providers catch this and translate
 * it into whichever ProviderException fits their context (e.g.
 * InvalidArgumentException for bad connect-time input,
 * ProviderPermanentException for an already-connected channel whose
 * stored host no longer resolves safely) -- that translation is
 * provider-specific interpretation and stays in the provider, per this
 * project's Global Constraint.
 */
class UnsafeRemoteHostException extends RuntimeException
{
    public static function forHost(string $host, string $reason): self
    {
        return new self("Remote host [{$host}] is not safe to connect to: {$reason}.");
    }

    public static function forScheme(string $scheme): self
    {
        return new self("URL scheme [{$scheme}] is not allowed; only https is.");
    }

    /**
     * Distinct from forHost(): used only when the input couldn't even be
     * parsed into a scheme/host, meaning SafeRemoteHost::assertSafeUrl()
     * is holding nothing but the raw, unparsed input -- which for a
     * credential-bearing URL (a webhook url is the canonical case) may still
     * contain the credential itself even though it isn't a valid URL.
     * Deliberately does not echo that input back.
     */
    public static function forMalformedUrl(): self
    {
        return new self('The supplied value is not a valid absolute URL.');
    }
}
