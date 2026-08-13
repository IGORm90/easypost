<?php

use App\Enums\OrganizationRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

// See LoginTest.php for why this Referer header is required: Sanctum's
// EnsureFrontendRequestsAreStateful only starts a session for requests it
// recognises as coming from the SPA — and Socialite's default *stateful*
// mode (see SocialAuthController's docblock) needs that session to store
// and later verify the OAuth `state` value.
beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

it('returns the provider authorize url for a whitelisted driver', function () {
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

    $response->assertRedirect(config('services.oauth_spa_redirect_url'));

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

it('establishes an authenticated session after a successful callback', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-123', 'email' => 'ann@example.com', 'name' => 'Ann',
    ]));

    $login = $this->get('/api/auth/oauth/github/callback');
    $cookieName = config('session.cookie');
    $sessionId = $login->getCookie($cookieName)?->getValue();
    expect($sessionId)->not->toBeNull();

    // See LoginTest.php: forgetGuards() forces the next call to
    // authenticate solely from the session cookie carried below.
    Auth::forgetGuards();

    $this->withCookie($cookieName, $sessionId)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'ann@example.com');
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

    $this->get('/api/auth/oauth/github/callback')->assertRedirect(config('services.oauth_spa_redirect_url'));

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

    $this->get('/api/auth/oauth/google/callback')->assertRedirect(config('services.oauth_spa_redirect_url'));

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
    $me = $this->withCookie($cookieName, $login->getCookie($cookieName)->getValue())
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
