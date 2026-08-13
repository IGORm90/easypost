<?php

use App\Enums\OrganizationRole;
use App\Models\User;

it('registers a user and creates their first organization', function () {
    $res = $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertCreated();

    $user = User::firstWhere('email', 'ann@example.com');
    expect($user)->not->toBeNull()
        ->and($user->organizations)->toHaveCount(1)
        ->and($user->organizations->first()->roleOf($user))->toBe(OrganizationRole::SUPERADMIN);
    $res->assertJsonPath('user.email', 'ann@example.com')
        ->assertJsonMissingPath('user.password')
        ->assertJsonPath('organization.name', "Ann's workspace");
});

it('gives a freshly registered user a non-null locale and timezone without a database refetch', function () {
    // Asserts against the response JSON specifically because that's built
    // from AuthController::register()'s in-memory $user — the very object
    // that would have carried nulls before User::$attributes gained its
    // timezone/locale defaults, since RegisterRequest never sets either
    // field and nothing in the register() flow calls refresh(). A
    // User::firstWhere()->timezone check here would only prove the DB
    // column default works, which was never in question.
    $res = $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertCreated();

    $res->assertJsonPath('user.timezone', 'UTC')
        ->assertJsonPath('user.locale', 'en');
});

it('lets a freshly registered user log in with the password they registered with', function () {
    // Guards against the register/login suites passing independently while
    // registration is actually broken end-to-end — e.g. if the `hashed`
    // cast on User (Task 5, outside this diff) were ever dropped, this
    // would still register fine and every login test would still pass
    // (they all seed via Hash::make() directly), while no registered user
    // could ever actually log in.
    $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertCreated();

    $this->withHeader('Referer', config('app.url'))
        ->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertOk()
        ->assertJsonPath('user.email', 'ann@example.com');
});

it('rejects duplicate emails', function () {
    User::factory()->create(['email' => 'ann@example.com']);
    $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'ann@example.com', 'password' => 'secret1234'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects duplicate emails regardless of case', function () {
    User::factory()->create(['email' => 'ann@example.com']);
    $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'Ann@Example.com', 'password' => 'secret1234'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects short passwords', function () {
    $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'ann@example.com', 'password' => 'short'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

it('never exposes password, remember_token or api_key on the user resource', function () {
    $res = $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertCreated();

    $res->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token')
        ->assertJsonMissingPath('user.api_key');
});

it('ignores privileged attributes sent by the caller during registration', function () {
    $res = $this->postJson('/api/auth/register', [
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'password' => 'secret1234',
        'is_superadmin' => true,
        'api_key' => 'attacker-supplied-key',
        'email_verified_at' => now()->toIso8601String(),
    ])->assertCreated();

    $user = User::firstWhere('email', 'ann@example.com');

    expect($user->is_superadmin)->toBeFalse()
        ->and($user->api_key)->toBeNull()
        ->and($user->email_verified_at)->toBeNull();

    $res->assertJsonMissingPath('user.is_superadmin')
        ->assertJsonMissingPath('user.api_key');
});
