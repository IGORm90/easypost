<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

/*
 * The first operator on a fresh production install. Registration is closed
 * (REGISTRATION_ENABLED=false), so the operator signs up once through the
 * door that is opened on purpose, then runs this to become a superadmin
 * (Pulse, Log Viewer, the admin API). The alternative was `tinker`, which
 * leaves no trace and invites typos in a production shell.
 */

it('makes an existing user a superadmin', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);
    expect($user->fresh()->is_superadmin)->toBeFalse();

    $this->artisan('user:superadmin', ['email' => 'ann@example.com'])
        ->expectsOutputToContain('is now a superadmin')
        ->assertExitCode(0);

    expect($user->fresh()->is_superadmin)->toBeTrue();
});

it('finds the user regardless of the case the operator types the email in', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);

    $this->artisan('user:superadmin', ['email' => 'Ann@Example.COM'])->assertExitCode(0);

    expect($user->fresh()->is_superadmin)->toBeTrue();
});

it('fails on an unknown email and changes nobody', function () {
    $bystander = User::factory()->create(['email' => 'ann@example.com']);

    $this->artisan('user:superadmin', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user')
        ->assertExitCode(1);

    expect($bystander->fresh()->is_superadmin)->toBeFalse()
        ->and(User::where('is_superadmin', true)->count())->toBe(0);
});

it('is idempotent: running it again succeeds and says nothing changed', function () {
    $user = User::factory()->create(['email' => 'ann@example.com', 'is_superadmin' => true]);

    $this->artisan('user:superadmin', ['email' => 'ann@example.com'])
        ->expectsOutputToContain('already a superadmin')
        ->assertExitCode(0);

    expect($user->fresh()->is_superadmin)->toBeTrue();
});

it('records the grant by user id, never by email', function () {
    $user = User::factory()->create(['email' => 'ann@example.com']);
    Log::shouldReceive('info')->once()->with('superadmin.granted', ['user_id' => $user->id]);

    $this->artisan('user:superadmin', ['email' => 'ann@example.com'])->assertExitCode(0);
});
