<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

// Deliberately *no* blanket Referer header here (unlike LoginTest.php /
// EmailVerificationTest.php): GET /api/auth/oauth/{driver}/redirect and
// .../callback are registered in routes/web.php specifically so they get a
// session unconditionally, regardless of Referer/Origin — see that file's
// comment on the oauth route group. A real callback is a top-level
// navigation issued by github.com/accounts.google.com, never carrying our
// own origin, so a test that forces our origin onto every request would
// hide exactly the bug this file exists to catch. Where a request in this
// file genuinely needs Sanctum's same-origin gate (calls to
// /api/auth/me, which stays behind statefulApi() in routes/api.php), the
// Referer header is set on that specific call instead.

// Eloquent model event listeners registered with SocialAccount::creating()
// (used by the atomicity test below) live on the model's static event
// dispatcher, not per-request state, so they'd otherwise leak into every
// later test in this file/process.
afterEach(function () {
    SocialAccount::flushEventListeners();
});

// Where a successful social login lands: the calendar, which moved from
// `/` to `/calendar` when the public landing page took over the root. A
// redirect to the bare SPA origin would now drop a freshly signed-in user
// onto the marketing page instead of their workspace.
function signedInHome(): string
{
    return rtrim((string) config('services.oauth_spa_redirect_url'), '/').'/calendar';
}

it('returns the provider authorize url for a whitelisted driver, with no Referer header', function () {
    Socialite::fake('github', SocialiteUser::fake());

    $this->getJson('/api/auth/oauth/github/redirect')
        ->assertOk()
        ->assertJsonStructure(['url']);
});

it('rejects an unknown driver before any Socialite call, on both endpoints', function () {
    Socialite::fake('github', SocialiteUser::fake());

    $this->getJson('/api/auth/oauth/twitter/redirect')->assertNotFound();
    $this->getJson('/api/auth/oauth/twitter/callback')->assertNotFound();

    expect(User::count())->toBe(0)
        ->and(SocialAccount::count())->toBe(0);
});

it('creates a new user and their first organization for a brand-new email', function () {
    $fakeUser = SocialiteUser::fake([
        'id' => 'gh-123',
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'token' => 'access-token-value',
        'refreshToken' => 'refresh-token-value',
    ]);
    Socialite::fake('github', $fakeUser);

    $response = $this->get('/api/auth/oauth/github/callback');

    $response->assertRedirect(signedInHome());

    $user = User::firstWhere('email', 'ann@example.com');
    expect($user)->not->toBeNull()
        ->and($user->organizations)->toHaveCount(1)
        ->and($user->organizations->first()->roleOf($user))->toBe(OrganizationRole::SUPERADMIN);

    $account = SocialAccount::firstWhere('provider_user_id', 'gh-123');
    expect($account)->not->toBeNull()
        ->and($account->provider)->toBe('github')
        ->and($account->user_id)->toBe($user->id);

    // GitHub's email is only ever populated with a verified primary
    // address (see SocialAccountService::isEmailVerifiedByProvider()), so
    // this counts as provider-verified.
    expect($user->email_verified_at)->not->toBeNull();
});

it('leaves no orphan user or organization if the social_accounts insert fails (transaction atomicity)', function () {
    // Simulates the failure SocialAccountService::findOrCreateUser()'s
    // outer DB::transaction() exists to guard against: a real race would
    // be a concurrent duplicate hitting the (provider, provider_user_id)
    // unique index between the existence check and the insert, but a
    // model 'creating' listener that throws reproduces the same "insert
    // fails after User::create() + OrganizationService::createForUser()
    // already ran" situation deterministically, without needing an actual
    // concurrent request.
    SocialAccount::creating(function () {
        throw new RuntimeException('simulated social_accounts insert failure');
    });

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-atomic', 'email' => 'atomic@example.com', 'name' => 'Atomic',
    ]));

    $this->get('/api/auth/oauth/github/callback');

    // Without the outer transaction, User::create() + the organization
    // (both committed inside resolveUser(), before the failing insert)
    // would have survived this. With it, nothing does.
    expect(User::where('email', 'atomic@example.com')->count())->toBe(0)
        ->and(Organization::where('name', "Atomic's workspace")->count())->toBe(0)
        ->and(SocialAccount::count())->toBe(0);
});

it('establishes an authenticated session after a successful callback carrying no Referer at all', function () {
    // The core regression test for the routes/web.php fix: previously
    // (routes declared in routes/api.php, gated by Sanctum's
    // statefulApi()), a request with no Referer/Origin never got a session
    // at all, so AuthService::login()'s $request->session()->regenerate()
    // would have thrown. No header is set anywhere in this test.
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-123', 'email' => 'ann@example.com', 'name' => 'Ann',
    ]));

    $login = $this->get('/api/auth/oauth/github/callback');
    $login->assertRedirect(signedInHome());

    $cookieName = config('session.cookie');
    $sessionId = $login->getCookie($cookieName)?->getValue();
    expect($sessionId)->not->toBeNull();

    // See LoginTest.php: forgetGuards() forces the next call to
    // authenticate solely from the session cookie carried below.
    Auth::forgetGuards();

    // /api/auth/me lives under routes/api.php and stays behind Sanctum's
    // statefulApi() gate, so *this* call (unlike the callback above) needs
    // a matching Referer to be recognised as coming from the SPA.
    $this->withHeader('Referer', config('app.url'))
        ->withCookie($cookieName, $sessionId)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'ann@example.com');
});

it('rejects a callback whose state does not match the session it was issued in', function () {
    // Exercises Socialite's *real* state check (no Socialite::fake() here
    // — fakes bypass hasInvalidState() entirely, which is exactly the
    // check this test needs to prove actually runs). The real GithubProvider
    // is used with whatever (possibly empty) credentials config/services.php
    // resolves in testing; hasInvalidState() is checked before any network
    // call is made, so no request ever reaches github.com.
    $redirect = $this->getJson('/api/auth/oauth/github/redirect')->assertOk();
    $cookieName = config('session.cookie');
    $sessionId = $redirect->getCookie($cookieName)?->getValue();
    expect($sessionId)->not->toBeNull();

    $callback = $this->withCookie($cookieName, $sessionId)
        ->get('/api/auth/oauth/github/callback?state=not-the-real-state&code=irrelevant');

    $callback->assertRedirect(config('services.oauth_spa_redirect_url').'/login?oauth_error=1');
    expect(User::count())->toBe(0)
        ->and(SocialAccount::count())->toBe(0);
});

it('rejects a callback with no prior redirect, so no session state to match at all', function () {
    $callback = $this->get('/api/auth/oauth/github/callback?state=anything&code=irrelevant');

    $callback->assertRedirect(config('services.oauth_spa_redirect_url').'/login?oauth_error=1');
    expect(User::count())->toBe(0);
});

it('fails cleanly, without leaking the provider error, when the provider callback errors out (e.g. denied consent)', function () {
    Socialite::fake('github', function () {
        throw new InvalidStateException('irrelevant-secret-detail');
    });

    $response = $this->get('/api/auth/oauth/github/callback');

    $response->assertRedirect(config('services.oauth_spa_redirect_url').'/login?oauth_error=1');
    $response->assertDontSee('irrelevant-secret-detail', false);
    expect(User::count())->toBe(0);
});

it('links a returning provider identity to its existing user without creating a duplicate', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => 'gh-123',
        'access_token' => 'old-token',
    ]);

    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-123', 'email' => 'ann@example.com', 'name' => 'Ann',
    ]));

    $this->get('/api/auth/oauth/github/callback')->assertRedirect(signedInHome());

    expect(User::where('email', 'ann@example.com')->count())->toBe(1)
        ->and(SocialAccount::where('provider', 'github')->where('provider_user_id', 'gh-123')->count())->toBe(1);
});

it('links a new provider to an existing user when the provider verified the email', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);

    // Google/OIDC assert verification via the standard `email_verified`
    // OIDC claim, carried in Socialite's raw user payload.
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'go-999', 'email' => 'ann@example.com', 'name' => 'Ann', 'email_verified' => true,
    ]));

    $this->get('/api/auth/oauth/google/callback')->assertRedirect(signedInHome());

    expect(User::where('email', 'ann@example.com')->count())->toBe(1);
    $account = SocialAccount::firstWhere('provider_user_id', 'go-999');
    expect($account)->not->toBeNull()->and($account->user_id)->toBe($user->id);
});

it('rejects linking to an existing user when the provider has not verified the email (account takeover guard)', function () {
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    // An attacker-controlled OIDC identity asserting the victim's email
    // without the provider vouching for it (`email_verified` false/absent)
    // must not be able to attach itself to the victim's account.
    Socialite::fake('oidc', SocialiteUser::fake([
        'id' => 'attacker-1', 'email' => 'victim@example.com', 'name' => 'Attacker', 'email_verified' => false,
    ]));

    $this->getJson('/api/auth/oauth/oidc/callback')->assertStatus(409);

    expect(User::where('email', 'victim@example.com')->count())->toBe(1)
        ->and(SocialAccount::count())->toBe(0);

    $victim->refresh();
    expect($victim->id)->not->toBeNull(); // untouched, still the only account
});

it('rejects a callback whose provider omitted an email entirely', function () {
    Socialite::fake('github', SocialiteUser::fake(['id' => 'gh-1', 'email' => null]));

    $this->getJson('/api/auth/oauth/github/callback')->assertStatus(422);

    expect(User::count())->toBe(0);
});

it('never exposes provider access or refresh tokens through any response, and stores them encrypted', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-123', 'email' => 'ann@example.com', 'name' => 'Ann',
        'token' => 'super-secret-access-token', 'refreshToken' => 'super-secret-refresh-token',
    ]));

    $login = $this->get('/api/auth/oauth/github/callback');
    $login->assertDontSee('super-secret-access-token', false);
    $login->assertDontSee('super-secret-refresh-token', false);

    $cookieName = config('session.cookie');
    Auth::forgetGuards();
    $me = $this->withHeader('Referer', config('app.url'))
        ->withCookie($cookieName, $login->getCookie($cookieName)->getValue())
        ->getJson('/api/auth/me')
        ->assertOk();
    $me->assertDontSee('super-secret-access-token', false);
    $me->assertDontSee('super-secret-refresh-token', false);

    // The `encrypted` cast means the raw DB column never holds the
    // plaintext token — read it straight off the table, bypassing the
    // model's cast, to prove that.
    $rawAccessToken = DB::table('social_accounts')->where('provider_user_id', 'gh-123')->value('access_token');
    expect($rawAccessToken)->not->toBeNull()
        ->and($rawAccessToken)->not->toContain('super-secret-access-token');

    $account = SocialAccount::firstWhere('provider_user_id', 'gh-123');
    expect($account->access_token)->toBe('super-secret-access-token'); // cast decrypts it back
});
