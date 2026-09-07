<?php

namespace App\Social\Exceptions;

use InvalidArgumentException;

/**
 * A `SupportsOptions::options()` implementation was asked for an
 * `$option` name it doesn't recognise (e.g. a chat provider asked for
 * 'subreddits'). Like UnknownProviderException, this is a caller/config
 * bug discovered before any network call is attempted, not a live
 * provider-call failure — it deliberately does NOT implement
 * ProviderException, so it is never caught by the publishing pipeline's
 * retry/refresh handling. IntegrationController maps it to a 404.
 */
class UnknownProviderOptionException extends InvalidArgumentException
{
    public static function forOption(string $providerIdentifier, string $option): self
    {
        return new self("Provider [{$providerIdentifier}] has no option named [{$option}].");
    }
}
