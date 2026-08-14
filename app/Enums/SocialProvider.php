<?php

namespace App\Enums;

/**
 * The OAuth drivers this application accepts on
 * `/api/auth/oauth/{driver}/*`. Because both SocialAuthController route
 * methods type-hint their `$driver` parameter with this enum, Laravel's
 * implicit enum route binding resolves it via `tryFrom()` and throws a 404
 * for anything else *before* the controller method — and therefore before
 * any `Socialite::driver()` call — ever runs. This is the whitelist the
 * task brief asks for; it doesn't need a second, hand-rolled check.
 */
enum SocialProvider: string
{
    case GitHub = 'github';
    case Google = 'google';
    case Oidc = 'oidc';
}
