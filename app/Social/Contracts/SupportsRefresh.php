<?php

namespace App\Social\Contracts;

use App\Models\Integration;
use App\Social\Dto\AuthResultDto;

/**
 * Optional capability: implement this on a provider whose access tokens
 * expire and can be refreshed without user interaction (e.g. OAuth
 * refresh-token grant). Providers whose tokens don't expire, or that
 * require a full re-authenticate() to renew, simply don't implement it.
 *
 * Task 16's refresh job checks `$provider instanceof SupportsRefresh`
 * before calling this — never assume it's present on every provider.
 */
interface SupportsRefresh
{
    /**
     * Exchange the Integration's stored refresh token for a fresh
     * access token. Throws ProviderAuthException if the refresh token
     * itself is no longer valid (the channel must be marked
     * refresh_needed and the user asked to reconnect).
     */
    public function refresh(Integration $i): AuthResultDto;
}
