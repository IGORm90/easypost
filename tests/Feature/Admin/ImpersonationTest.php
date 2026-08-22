<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * These tests exercise real cookie-based sessions end to end (login via
 * the real endpoint, carry the returned session cookie forward by hand,
 * Auth::forgetGuards() between calls) rather than actingAs() throughout —
 * see tests/Feature/Auth/LoginTest.php's comment for why actingAs() alone
 * can't prove anything about session state, and impersonation's entire
 * contract (who stop() returns to, what a nested start() sees) lives in
 * the session, not the guard.
 */
beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

function loginAndGetCookie(string $email, string $password): string
{
    $cookieName = config('session.cookie');

    $response = test()->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
        ->assertOk();

    $cookie = $response->getCookie($cookieName)?->getValue();
    expect($cookie)->not->toBeNull();

    return $cookie;
}

it('forbids a non-superadmin from starting impersonation', function () {
    $actor = User::factory()->create(['password' => Hash::make('secret1234')]);
    $target = User::factory()->create();

    $cookieName = config('session.cookie');
    $cookie = loginAndGetCookie($actor->email, 'secret1234');
    Auth::forgetGuards();

    test()->withCookie($cookieName, $cookie)
        ->postJson("/api/admin/impersonate/{$target->id}")
        ->assertForbidden();
});

it('lets a superadmin impersonate another user, restricts sensitive actions, and lets them stop back to themselves', function () {
    $cookieName = config('session.cookie');
    $actor = User::factory()->create(['password' => Hash::make('secret1234'), 'is_superadmin' => true]);
    $target = User::factory()->create();

    // Log::shouldReceive(), not Log::spy()+shouldHaveReceived(): the
    // latter's assertion method only exists on the Mockery spy instance
    // Facade::__callStatic() forwards to at runtime, which Larastan can't
    // resolve statically (the same class of dynamic-facade-call gap as
    // tests/Pest.php's documented Pest TestCall ignore). shouldReceive()
    // is a real static method on the Facade base class, so both the
    // expectation *and* the eventual `it was called` assertion (verified
    // by Mockery::close() in teardown) are visible to PHPStan.
    Log::shouldReceive('info')
        ->once()
        ->with('impersonation.started', ['actor_id' => $actor->id, 'target_id' => $target->id]);
    Log::shouldReceive('info')
        ->once()
        ->with('impersonation.stopped', ['actor_id' => $actor->id, 'target_id' => $target->id]);

    $cookie = loginAndGetCookie($actor->email, 'secret1234');
    Auth::forgetGuards();

    $start = test()->withCookie($cookieName, $cookie)
        ->postJson("/api/admin/impersonate/{$target->id}")
        ->assertOk();
    $start->assertJsonPath('user.id', $target->id);
    $impersonatingCookie = $start->getCookie($cookieName)?->getValue();
    expect($impersonatingCookie)->not->toBeNull()->and($impersonatingCookie)->not->toBe($cookie);

    Auth::forgetGuards();
    test()->withCookie($cookieName, $impersonatingCookie)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $target->id);

    // Restricted set: rotating the API key must be unreachable while
    // impersonating, enforced centrally by DenyWhileImpersonating.
    Auth::forgetGuards();
    test()->withCookie($cookieName, $impersonatingCookie)
        ->postJson('/api/profile/api-key/rotate')
        ->assertForbidden();

    Auth::forgetGuards();
    $stop = test()->withCookie($cookieName, $impersonatingCookie)
        ->postJson('/api/admin/impersonate/stop')
        ->assertOk();
    $stop->assertJsonPath('user.id', $actor->id);
    $stoppedCookie = $stop->getCookie($cookieName)?->getValue();
    expect($stoppedCookie)->not->toBeNull();

    Auth::forgetGuards();
    test()->withCookie($cookieName, $stoppedCookie)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $actor->id);
});

it('blocks a nested impersonation attempt started from within an already-impersonated session', function () {
    $cookieName = config('session.cookie');
    $actor = User::factory()->create(['password' => Hash::make('secret1234'), 'is_superadmin' => true]);
    // The target is itself a superadmin so it passes the `superadmin`
    // route middleware on the nested attempt — proving the block below
    // comes from the session-level nested-start guard, not just the role
    // check.
    $target = User::factory()->create(['is_superadmin' => true]);
    $thirdParty = User::factory()->create();

    $cookie = loginAndGetCookie($actor->email, 'secret1234');
    Auth::forgetGuards();

    $start = test()->withCookie($cookieName, $cookie)
        ->postJson("/api/admin/impersonate/{$target->id}")
        ->assertOk();
    $impersonatingCookie = $start->getCookie($cookieName)?->getValue();

    Auth::forgetGuards();
    test()->withCookie($cookieName, $impersonatingCookie)
        ->postJson("/api/admin/impersonate/{$thirdParty->id}")
        ->assertForbidden();
});

it('does not let a normal user promote themselves by calling stop', function () {
    $cookieName = config('session.cookie');
    $user = User::factory()->create(['password' => Hash::make('secret1234'), 'is_superadmin' => false]);

    $cookie = loginAndGetCookie($user->email, 'secret1234');
    Auth::forgetGuards();

    test()->withCookie($cookieName, $cookie)
        ->postJson('/api/admin/impersonate/stop')
        ->assertStatus(409);

    Auth::forgetGuards();
    test()->withCookie($cookieName, $cookie)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);

    expect($user->fresh()->is_superadmin)->toBeFalse();
});

it('centrally blocks any route tagged deny_while_impersonating, not just the api-key route', function () {
    // Proves the restriction is generic (attach the alias, get the
    // block), not hardcoded to the one endpoint that happens to exist
    // today. Password-change and account-deletion land in later tasks and
    // will pick up the same alias.
    // 'api' first: it's what carries statefulApi()'s
    // EnsureFrontendRequestsAreStateful (session support for first-party
    // SPA requests) — the same middleware every real route in
    // routes/api.php picks up implicitly from that file's `api:` group,
    // which these ad hoc test routes don't get for free.
    Route::middleware(['api', 'auth:sanctum', 'deny_while_impersonating'])
        ->post('_test-only/change-password', fn () => response()->noContent());
    Route::middleware(['api', 'auth:sanctum', 'deny_while_impersonating'])
        ->delete('_test-only/delete-account', fn () => response()->noContent());

    $cookieName = config('session.cookie');
    $actor = User::factory()->create(['password' => Hash::make('secret1234'), 'is_superadmin' => true]);
    $target = User::factory()->create();

    $cookie = loginAndGetCookie($actor->email, 'secret1234');
    Auth::forgetGuards();

    $start = test()->withCookie($cookieName, $cookie)
        ->postJson("/api/admin/impersonate/{$target->id}")
        ->assertOk();
    $impersonatingCookie = $start->getCookie($cookieName)?->getValue();

    Auth::forgetGuards();
    test()->withCookie($cookieName, $impersonatingCookie)
        ->postJson('/_test-only/change-password')
        ->assertForbidden();

    Auth::forgetGuards();
    test()->withCookie($cookieName, $impersonatingCookie)
        ->deleteJson('/_test-only/delete-account')
        ->assertForbidden();
});

it('forbids impersonating oneself', function () {
    $cookieName = config('session.cookie');
    $actor = User::factory()->create(['password' => Hash::make('secret1234'), 'is_superadmin' => true]);

    $cookie = loginAndGetCookie($actor->email, 'secret1234');
    Auth::forgetGuards();

    test()->withCookie($cookieName, $cookie)
        ->postJson("/api/admin/impersonate/{$actor->id}")
        ->assertStatus(422);
});

/*
 * Task 1.7: until now nothing in the API said whether the current session
 * was borrowed. The SPA has to know two things it could not previously
 * ask for — may this user open the admin screen at all, and is this
 * session currently someone else's — and /auth/me is where it asks.
 *
 * Both live on the endpoint's response rather than inside UserResource:
 * that resource's docblock calls its array the entire contract of what it
 * can return, and RegistrationTest asserts `user.is_superadmin` is absent
 * from it. An instance-level privilege has no business riding along with
 * every login response and every member listing.
 */
it('tells an ordinary session that it is nobody else and holds no instance privilege', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('is_superadmin', false)
        ->assertJsonPath('impersonator', null)
        // The flag stays off the user object itself.
        ->assertJsonMissingPath('user.is_superadmin');
});

it('reports the instance privilege to the superadmin who has it', function () {
    $this->actingAs(User::factory()->create(['is_superadmin' => true]));

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('is_superadmin', true);
});

it('names the real actor behind an impersonated session, and stops naming them once it ends', function () {
    $cookieName = config('session.cookie');
    $actor = User::factory()->create([
        'name' => 'Robin Ash',
        'password' => Hash::make('secret1234'),
        'is_superadmin' => true,
    ]);
    $target = User::factory()->create(['name' => 'Dana Levin']);

    $cookie = loginAndGetCookie($actor->email, 'secret1234');
    Auth::forgetGuards();

    test()->withCookie($cookieName, $cookie)
        ->postJson("/api/admin/impersonate/{$target->id}")
        ->assertOk();
    Auth::forgetGuards();

    // The borrowed session reports the target as the user, the actor as
    // the impersonator — this pair is the only thing the banner can be
    // built on — and no instance privilege, because the target has none.
    test()->withCookie($cookieName, $cookie)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.name', 'Dana Levin')
        ->assertJsonPath('impersonator.id', $actor->id)
        ->assertJsonPath('impersonator.name', 'Robin Ash')
        ->assertJsonPath('is_superadmin', false);
    Auth::forgetGuards();

    test()->withCookie($cookieName, $cookie)->postJson('/api/admin/impersonate/stop')->assertOk();
    Auth::forgetGuards();

    test()->withCookie($cookieName, $cookie)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.name', 'Robin Ash')
        ->assertJsonPath('impersonator', null)
        ->assertJsonPath('is_superadmin', true);
});
