<?php

namespace App\Social\Dto;

/**
 * Where to send the user's browser to start (or continue) an OAuth-style
 * authorization flow for a provider, plus whatever the provider needs
 * remembered across the redirect round-trip.
 */
final readonly class AuthUrlDto
{
    /**
     * @param  string  $url  Fully-qualified URL to redirect the user to.
     * @param  array<string, mixed>  $extra  Provider-specific values the caller must persist (session, signed cookie, …) and hand back on the callback — e.g. a PKCE code verifier. Opaque to everything outside the provider.
     */
    public function __construct(
        public string $url,
        public array $extra = [],
    ) {}
}
