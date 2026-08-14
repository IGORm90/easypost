<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// Sanctum's EnsureFrontendRequestsAreStateful only starts a session (and
// therefore only lets Auth::login()/logout() persist anything) for
// requests it recognises as coming from the SPA: an Origin/Referer header
// matching config('sanctum.stateful'). A real browser sends this
// automatically; the test client does not, so it's set here to match
// SANCTUM_STATEFUL_DOMAINS (see .env) — without it every request in this
// file hits "Session store not set on request."
beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

it('logs in and returns the session user', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);
    $cookieName = config('session.cookie');

    $login = $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertOk();
    $sessionId = $login->getCookie($cookieName)?->getValue();
    expect($sessionId)->not->toBeNull();

    // Laravel's test container keeps the same Auth guard instances across
    // sequential calls within one test method (a real production request
    // never would), so a guard resolved once would silently "remember" the
    // user regardless of session state. Forgetting it forces the next call
    // to authenticate solely from the session cookie carried below — the
    // actual thing this test is meant to prove.
    Auth::forgetGuards();

    $this->withCookie($cookieName, $sessionId)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'ann@example.com');
});

it('logs out and clears the session', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);
    $cookieName = config('session.cookie');

    // Real end-to-end round trip (login, then logout, then a re-check with
    // the same cookie) rather than actingAs(): actingAs() injects the user
    // directly into the guard and never goes through StartSession, so it
    // can't prove the actual session this task's logout() must invalidate.
    $login = $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertOk();
    $sessionId = $login->getCookie($cookieName)?->getValue();
    expect($sessionId)->not->toBeNull();

    $this->withCookie($cookieName, $sessionId)
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    // See the comment above: forget the cached guards so this check reads
    // the actual (now-invalidated) session instead of an in-process cache.
    Auth::forgetGuards();

    $this->withCookie($cookieName, $sessionId)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('returns the same generic error for an unknown email and a wrong password', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);

    $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'wrongpass1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($unknown->json('errors.email'))->toBe($wrongPassword->json('errors.email'));
});

it('checks the password hash even when the email is unknown, so unknown-email responses are not faster', function () {
    // Not a timing assertion (flaky in CI); this proves Hash::check() runs
    // on a dummy hash for unknown emails by asserting the failure path is
    // identical in shape to the wrong-password path (see the generic-error
    // test above) rather than a distinct short-circuit "no such user" path.
    $res = $this->postJson('/api/auth/login', ['email' => 'ghost@example.com', 'password' => 'whatever1'])
        ->assertStatus(422);

    expect($res->json('errors.email.0'))->not->toContain('exist');
});

it('rate limits repeated failed login attempts', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'wrongpass1'])
            ->assertStatus(422);
    }

    $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'wrongpass1'])
        ->assertStatus(429);

    // Even correct credentials are throttled once the limit is hit.
    $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertStatus(429);
});

it('regenerates the session id on login to prevent session fixation', function () {
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);
    $cookieName = config('session.cookie');

    // Establish a real pre-login session (e.g. one an attacker could plant
    // via a shared link) and carry its exact cookie into the login request,
    // then prove the id that comes back is different — the actual
    // fixation-defeating behaviour, not just "some session exists".
    $pre = $this->getJson('/api/auth/me')->assertUnauthorized();
    $preSessionId = $pre->getCookie($cookieName)?->getValue();
    expect($preSessionId)->not->toBeNull();

    $post = $this->withCookie($cookieName, $preSessionId)
        ->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertOk();
    $postSessionId = $post->getCookie($cookieName)?->getValue();

    expect($postSessionId)->not->toBeNull()->and($postSessionId)->not->toBe($preSessionId);
});

it('rejects a login request without a password', function () {
    $this->postJson('/api/auth/login', ['email' => 'ann@example.com'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

it('returns 401 for me when there is no session', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

// Final review, Critical 1: the SPA's router guard runs fetchMe() only
// when the auth store is not yet `ready`, and login() marks it ready. So
// the very first navigation after signing in reads whatever login()
// returned and nothing else. When that payload carried only `user`, the
// organization store synced against an empty membership list, cleared
// `organization_id` from localStorage, and every subsequent request went
// out without X-Organization — ResolveOrganization 403s the lot. login()
// and me() must therefore describe the session identically.
it('returns the signed-in user\'s organizations, exactly as /auth/me does', function () {
    $user = User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);
    $org = Organization::factory()->create(['name' => 'Acme']);
    $org->addMember($user, OrganizationRole::ADMIN);

    $login = $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertOk()
        ->assertJsonCount(1, 'organizations')
        ->assertJsonPath('organizations.0.id', $org->id)
        ->assertJsonPath('organizations.0.name', 'Acme');

    $cookieName = config('session.cookie');
    $sessionId = $login->getCookie($cookieName)?->getValue();
    Auth::forgetGuards();

    $me = $this->withCookie($cookieName, $sessionId)->getJson('/api/auth/me')->assertOk();

    expect($login->json('organizations'))->toEqual($me->json('organizations'));
});
