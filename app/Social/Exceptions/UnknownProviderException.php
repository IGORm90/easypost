<?php

namespace App\Social\Exceptions;

use InvalidArgumentException;

/**
 * ProviderRegistry::get() was called with an identifier that isn't
 * registered in config('social.providers'). Not part of the
 * retry-decision taxonomy above — this is a caller/config bug, not a
 * network-call failure.
 */
class UnknownProviderException extends InvalidArgumentException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self("No social provider is registered for identifier [{$identifier}].");
    }
}
