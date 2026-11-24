<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

// The landing page is served from its own host (easy-posting.com) while the
// session cookie belongs to the app host (app.easy-posting.com). To show
// "Calendar" instead of "Sign in" to a signed-in member, the landing page asks
// the app host who is signed in — a credentialed cross-origin GET. That only
// works when the app host (a) treats the landing origin as stateful, so the
// session cookie is read, and (b) answers CORS with the exact origin and
// Allow-Credentials, never `*`.
beforeEach(function () {
    config([
        'sanctum.stateful' => ['app.example.com', 'landing.test'],
        'cors.allowed_origins' => ['https://landing.test'],
    ]);
});

it('lets the landing origin read the session user with credentials', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    Auth::forgetGuards();

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'https://landing.test', 'Referer' => 'https://landing.test/'])
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://landing.test')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('does not echo an origin that is not configured', function () {
    // With a single allowed origin the CORS layer always answers with that one
    // origin, and the browser refuses the response for any other site. What must
    // never happen is the foreign origin being echoed back, or a wildcard.
    $allowed = $this->withHeaders(['Origin' => 'https://evil.test', 'Referer' => 'https://evil.test/'])
        ->getJson('/api/auth/me')
        ->headers->get('Access-Control-Allow-Origin');

    expect($allowed)->not->toBe('https://evil.test')
        ->and($allowed)->not->toBe('*');
});

it('answers the preflight for the landing origin on the me endpoint', function () {
    $this->withHeaders([
        'Origin' => 'https://landing.test',
        'Access-Control-Request-Method' => 'GET',
    ])
        ->options('/api/auth/me')
        ->assertSuccessful()
        ->assertHeader('Access-Control-Allow-Origin', 'https://landing.test')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('does not open credentialed CORS on other API endpoints', function () {
    $this->withHeaders(['Origin' => 'https://landing.test', 'Referer' => 'https://landing.test/'])
        ->getJson('/api/integrations')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});

it('exposes the app url to the SPA shell so the landing host knows where the app lives', function () {
    config(['app.url' => 'https://app.example.com']);

    $this->get('/')
        ->assertOk()
        ->assertSee('<meta name="app-url" content="https://app.example.com">', false);
});
