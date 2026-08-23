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

        // Distinct from 'notifications' above (this app's own Slack alert
        // channel): these are the OAuth app credentials Task 15's
        // SlackProvider uses to let end users connect THEIR Slack
        // workspace as a publishing channel.
        'integration' => [
            'client_id' => env('SLACK_INTEGRATION_CLIENT_ID'),
            'client_secret' => env('SLACK_INTEGRATION_CLIENT_SECRET'),
        ],
    ],

    // OAuth app credentials for Task 15's DiscordProvider (connecting a
    // Discord channel via the `webhook.incoming` scope).
    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
    ],

    /*
     * Block 5's eight networks. Every one of them is a standard OAuth 2.0
     * client credential pair, registered per deployment — see README.md
     * for how to obtain each. The redirect URI is the same for all of
     * them (`services.integrations_redirect_url`): the SPA receives the
     * provider's redirect and posts the code back, so a provider never
     * needs a URL of its own.
     *
     * Machine identifiers, not brand names: X is 'twitter' and Threads is
     * 'meta-threads' because the guard in
     * tests/Unit/Architecture/ProviderIsolationTest.php matches a
     * provider's name as a substring of any quoted string, and 'x' /
     * 'threads' collide with vocabulary this app already owns. The names
     * users see come from each provider's name().
     */
    'twitter' => [
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
    ],

    'reddit' => [
        'client_id' => env('REDDIT_CLIENT_ID'),
        'client_secret' => env('REDDIT_CLIENT_SECRET'),
        /*
         * Reddit blocks on User-Agent harder than on request rate, and a
         * generic or absent one is refused outright. It must identify the
         * app and a contact account, e.g.
         * 'easypost/1.0 by /u/your-account'.
         */
        'user_agent' => env('REDDIT_USER_AGENT'),
    ],

    /*
     * One Meta app serves Facebook Page, both Instagram providers and
     * Threads, but they are configured separately: a deployment may hold
     * review approval for one and not the others, and sharing one env
     * pair would make that indistinguishable from a misconfiguration.
     */
    'facebook_page' => [
        'client_id' => env('FACEBOOK_PAGE_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_PAGE_CLIENT_SECRET'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
    ],

    'instagram_standalone' => [
        'client_id' => env('INSTAGRAM_STANDALONE_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_STANDALONE_CLIENT_SECRET'),
    ],

    'meta_threads' => [
        'client_id' => env('META_THREADS_CLIENT_ID'),
        'client_secret' => env('META_THREADS_CLIENT_SECRET'),
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
