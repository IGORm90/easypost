<?php

use App\Models\Invite;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Production launches with REGISTRATION_ENABLED=false (DEPLOY.md): the first
 * account is made by the operator, and nobody else may create one until that
 * is deliberately opened. "Closed" means no NEW account by any route — the
 * password form and a first-time social login alike. Everyone who already has
 * an account keeps working.
 *
 * NOTE for whoever opens the product to other people: an Invite can only be
 * accepted by an EXISTING, verified user (InviteService::accept), so with
 * registration closed an invitee has no way to get an account. Closed
 * registration is a launch gate, not an invite-only mode.
 */

// Sanctum starts a session only for requests that look like they came from
// the SPA; see LoginTest.php for why the Referer is set.
beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

it('tells the SPA whether registration is open, without authentication', function (bool $enabled) {
    config(['auth.registration_enabled' => $enabled]);

    $this->getJson('/api/auth/registration')
        ->assertOk()
        ->assertExactJson(['enabled' => $enabled]);
})->with([true, false]);

it('is open by default, so local development and the existing suite are unaffected', function () {
    expect(config('auth.registration_enabled'))->toBeTrue();
});

it('refuses to register anyone while registration is closed', function () {
    config(['auth.registration_enabled' => false]);
    Notification::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertForbidden();

    expect(User::count())->toBe(0)
        ->and(Organization::count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses before validating, so a closed door does not leak which emails are taken', function () {
    config(['auth.registration_enabled' => false]);
    User::factory()->create(['email' => 'taken@example.com']);

    // An open form answers 422 "email has already been taken" here; a closed
    // one must answer the same 403 for a taken and a fresh address.
    $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'taken@example.com', 'password' => 'secret1234',
    ])->assertForbidden();
});

it('lets an existing user keep logging in while registration is closed', function () {
    config(['auth.registration_enabled' => false]);
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('secret1234')]);

    $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertOk();
});

it('does not let a first-time social login create an account while registration is closed', function () {
    config(['auth.registration_enabled' => false]);
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 'gh-new', 'name' => 'Ann', 'email' => 'ann@example.com',
    ]));

    $this->get('/api/auth/oauth/github/callback')->assertForbidden();

    expect(User::count())->toBe(0)
        ->and(Organization::count())->toBe(0)
        ->and(SocialAccount::count())->toBe(0);
});

it('still signs in a returning social identity while registration is closed', function () {
    config(['auth.registration_enabled' => false]);
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

    $this->get('/api/auth/oauth/github/callback')->assertRedirect();

    expect(User::count())->toBe(1);
});

it('still lets an existing user accept an invite while registration is closed', function () {
    config(['auth.registration_enabled' => false]);
    $org = Organization::factory()->create();
    $invite = Invite::factory()->for($org)->create(['role' => 'USER', 'expires_at' => now()->addDay()]);
    $user = User::factory()->create(['email' => $invite->email]);

    $this->actingAs($user)->postJson("/api/invites/{$invite->token}/accept")->assertOk();
});
