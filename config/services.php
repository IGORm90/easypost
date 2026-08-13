<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Generic OpenID Connect. laravel/socialite has no built-in generic
    // OIDC driver, so this is consumed by the custom one registered in
    // AppServiceProvider (App\Services\Auth\Socialite\OidcProvider) —
    // points at whatever OIDC-compliant identity provider the deployment
    // configures (Okta, Keycloak, Azure AD, ...).
    'oidc' => [
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect' => env('OIDC_REDIRECT_URI'),
        'base_url' => env('OIDC_BASE_URL'),
    ],

    // Where SocialAuthController::callback() sends the browser after a
    // successful OAuth login. Deliberately read from config (never from a
    // request parameter) so the callback can't be turned into an open
    // redirect by an attacker-supplied `?redirect=` value.
    'oauth_spa_redirect_url' => env('OAUTH_SPA_REDIRECT_URL', env('APP_URL', 'http://localhost')),

    // The SPA page that receives a provider's OAuth redirect when
    // connecting a channel (Task 13), passed as `redirect_uri` to
    // SocialProviderInterface::generateAuthUrl()/authenticate(). That page
    // reads `code`/`state` (or whatever the provider appended) from its
    // own URL and POSTs them to
    // `/api/integrations/{provider}/callback` — this backend never
    // receives the provider's redirect directly, so (unlike
    // oauth_spa_redirect_url above) there is no open-redirect concern
    // here: this URL is never used in an HTTP redirect response, only
    // handed to the provider as the target for ITS redirect.
    'integrations_redirect_url' => env(
        'INTEGRATIONS_REDIRECT_URL',
        rtrim(env('OAUTH_SPA_REDIRECT_URL', env('APP_URL', 'http://localhost')), '/').'/integrations/callback'
    ),

];
