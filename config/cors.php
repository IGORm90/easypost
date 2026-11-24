<?php

/*
 * Cross-origin access to the API is closed except for one question the public
 * landing page has to ask.
 *
 * The landing page lives on its own host (easy-posting.com) while the session
 * cookie belongs to the app host (app.easy-posting.com). To show "Calendar"
 * rather than "Sign in" to a member who is already signed in, the landing page
 * calls GET /api/auth/me on the app host with credentials. Both hosts share a
 * registrable domain, so this is same-site and the SameSite=Lax session cookie
 * is sent; the browser still insists on an exact Allow-Origin plus
 * Allow-Credentials, which is what this file provides.
 *
 * Without this file Laravel falls back to its framework default, which is
 * `Access-Control-Allow-Origin: *` without credentials: a credentialed fetch
 * is blocked by the browser, the landing page concludes "signed out", and
 * every visitor sees the sign-in buttons.
 *
 * Deliberately narrow:
 *  - `paths` lists that one endpoint, not `api/*`: no other route answers a
 *    cross-origin credentialed request.
 *  - `allowed_origins` is an explicit list from CORS_ALLOWED_ORIGINS, never
 *    `*` (the spec forbids `*` together with credentials, and an echoed
 *    arbitrary origin would let any site read the member's profile).
 *  - Empty by default, so an install without a separate landing host (local
 *    development, a single-host deploy) opens nothing.
 *
 * The landing origin must ALSO be in SANCTUM_STATEFUL_DOMAINS, otherwise
 * Sanctum does not start the session for that request and /auth/me answers
 * 401 even though CORS passed.
 */
return [

    'paths' => ['api/auth/me'],

    'allowed_methods' => ['GET', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-Organization'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => true,

];
