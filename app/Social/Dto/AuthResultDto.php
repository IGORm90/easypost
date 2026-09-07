<?php

namespace App\Social\Dto;

use Carbon\CarbonImmutable;

/**
 * Outcome of a successful `SocialProviderInterface::authenticate()` or
 * `SupportsRefresh::refresh()` call: everything Task 12's Integration
 * service needs to create or update one `integrations` row. Never
 * contains raw HTTP response shapes — providers translate their own
 * network's response into this before returning.
 */
final readonly class AuthResultDto
{
    /**
     * @param  string  $internalId  The account/channel id on the remote network (e.g. Telegram chat id), used to detect re-connecting the same channel.
     * @param  string  $name  Human-readable display name for the connected channel, shown in the UI.
     * @param  string  $token  Access token. Callers are responsible for encrypting it at rest; providers hand back plaintext.
     * @param  string|null  $refreshToken  Refresh token, when the network issues one.
     * @param  CarbonImmutable|null  $expiresAt  When $token expires, if the network says. Null means "does not expire" or "unknown", not "expired".
     * @param  string|null  $picture  URL of the connected account's avatar/profile picture, if available.
     * @param  array<string, mixed>  $settings  Provider-specific extra state to persist verbatim on the Integration (e.g. a workspace channel id chosen during connect).
     */
    public function __construct(
        public string $internalId,
        public string $name,
        public string $token,
        public ?string $refreshToken = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $picture = null,
        public array $settings = [],
    ) {}
}
