<?php

namespace App\Services\Integrations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\Exceptions\InvalidOAuthStateException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * CSRF protection for the "connect a channel" OAuth flow (Task 13's
 * security requirement). The `state` round-tripped through
 * `SocialProviderInterface::generateAuthUrl()`/the provider's redirect is
 * not just an opaque provider-echo value — it is this application's proof
 * that the callback completing the flow is the same organization+user
 * that started it.
 *
 * Design: `generate()` mints a cryptographically random 64-character
 * token (not a signed/derivable value — see below for why) and stores the
 * (organization_id, user_id) pair it is bound to in the cache for 10
 * minutes. `consume()` looks it up, requires an EXACT match on both
 * organization and user, and deletes it on success — so:
 *   - short-lived: TTL_SECONDS caps how long a leaked/intercepted state
 *     (e.g. via browser history or a Referer header) remains usable;
 *   - single-use: a second consume() of the same token after a successful
 *     first one finds nothing in the cache and fails;
 *   - bound to org+user: `consume()` takes the CURRENT request's
 *     organization+user and rejects any mismatch, so a token minted for
 *     one organization/user can never complete a connect for another —
 *     this is what lets `IntegrationService::connect()` trust the
 *     `Organization` it's given (see that class's docblock) without
 *     re-deriving it from anything request-forgeable.
 *
 * Cryptographically random rather than a signed payload (e.g. a JWT
 * encoding org+user directly) is a deliberate choice: a signed-but-
 * unregistered token would still need a server-side single-use record to
 * enforce "consumed at most once", so the cache entry is required either
 * way — at which point a random opaque key is simpler and leaks nothing
 * if logged (a signed payload embeds the org/user ids in plaintext-
 * readable base64 wherever it appears, e.g. access logs, browser
 * history).
 *
 * The callback endpoint deliberately does NOT depend on the
 * `organization` middleware/`X-Organization` header (see
 * IntegrationService's docblock: the callback "may not have a fully
 * resolved tenant context the way a normal organization-middleware-gated
 * request does"). The organization for the connect is the one bound to
 * the state, full stop — `consume()` is the only place it is decided.
 */
class OAuthStateService
{
    private const int TTL_SECONDS = 600;

    private const string CACHE_PREFIX = 'integration_oauth_state:';

    public function generate(Organization $organization, User $user): string
    {
        $state = Str::random(64);

        Cache::put(self::key($state), [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ], self::TTL_SECONDS);

        return $state;
    }

    /**
     * Resolves and single-use-consumes $state, returning the Organization
     * it was minted for. Throws InvalidOAuthStateException if $state is
     * unknown, expired, already consumed, or was minted for a different
     * user than $user — the organization check is implicit: only the
     * organization stored alongside the matching user is ever returned,
     * so there is no separate "organization" branch to get wrong.
     */
    public function consume(string $state, User $user): Organization
    {
        $key = self::key($state);
        $payload = Cache::get($key);

        if (! is_array($payload) || ($payload['user_id'] ?? null) !== $user->id) {
            throw new InvalidOAuthStateException('This connection request is invalid or has expired.');
        }

        $organization = Organization::find($payload['organization_id'] ?? null);

        if (! $organization instanceof Organization || $organization->roleOf($user) === null) {
            throw new InvalidOAuthStateException('This connection request is invalid or has expired.');
        }

        Cache::forget($key);

        return $organization;
    }

    private static function key(string $state): string
    {
        return self::CACHE_PREFIX.$state;
    }
}
