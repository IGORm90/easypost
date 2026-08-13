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
