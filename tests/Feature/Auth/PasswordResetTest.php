<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

// See LoginTest.php for why this Referer header is required.
beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

/**
 * Registers via /forgot with Notification::fake(), pulls the real token out
 * of the captured ResetPasswordNotification instance (same technique the
 * framework's own password-reset tests use) rather than reaching into the
 * password_reset_tokens table directly, so this stays a real round trip
 * through the actual broker.
 */
function captureResetToken(string $email): string
{
    test()->postJson('/api/auth/password/forgot', ['email' => $email])->assertOk();

    $token = null;
    Notification::assertSentTo(
        User::firstWhere('email', $email),
        ResetPasswordNotification::class,
        function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        }
    );

    return $token;
}

it('sends a reset link notification for a known email', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ann@example.com']);

    $this->postJson('/api/auth/password/forgot', ['email' => 'ann@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('sends the reset link even when email_notifications is disabled', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ann@example.com', 'email_notifications' => false]);

    $this->postJson('/api/auth/password/forgot', ['email' => 'ann@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('returns an identical response for an unknown email as for a known one', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ann@example.com']);

    $known = $this->postJson('/api/auth/password/forgot', ['email' => 'ann@example.com'])->assertOk();
    $unknown = $this->postJson('/api/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk();

    expect($known->json())->toBe($unknown->json());
    expect($known->status())->toBe($unknown->status());
});

it('rate limits repeated forgot-password requests for the same email', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ann@example.com']);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/auth/password/forgot', ['email' => 'ann@example.com'])->assertOk();
    }

    $this->postJson('/api/auth/password/forgot', ['email' => 'ann@example.com'])->assertStatus(429);
});

it('resets the password with a valid token and changes the stored hash', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('oldpassword1')]);
    $token = captureResetToken('ann@example.com');

    $this->postJson('/api/auth/password/reset', [
        'email' => 'ann@example.com',
        'token' => $token,
        'password' => 'newpassword1',
    ])->assertOk();

    expect(Hash::check('newpassword1', $user->fresh()->password))->toBeTrue();
});

it('validates the new password with the same minimum length as registration', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ann@example.com']);
    $token = captureResetToken('ann@example.com');

    $this->postJson('/api/auth/password/reset', [
        'email' => 'ann@example.com',
        'token' => $token,
        'password' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('makes the reset token single-use: replaying it after a successful reset fails', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('oldpassword1')]);
    $token = captureResetToken('ann@example.com');

    $this->postJson('/api/auth/password/reset', [
        'email' => 'ann@example.com',
        'token' => $token,
        'password' => 'newpassword1',
    ])->assertOk();

    $this->postJson('/api/auth/password/reset', [
        'email' => 'ann@example.com',
        'token' => $token,
        'password' => 'anotherpassword1',
    ])->assertStatus(422);
});

it('rejects an unknown or invalid token', function () {
    User::factory()->create(['email' => 'ann@example.com']);

    $this->postJson('/api/auth/password/reset', [
        'email' => 'ann@example.com',
        'token' => 'not-a-real-token',
        'password' => 'newpassword1',
    ])->assertStatus(422);
});

it('invalidates existing sessions after a successful password reset', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ann@example.com', 'password' => Hash::make('oldpassword1')]);
    $cookieName = config('session.cookie');

    $login = $this->postJson('/api/auth/login', ['email' => 'ann@example.com', 'password' => 'oldpassword1'])
        ->assertOk();
    $sessionId = $login->getCookie($cookieName)->getValue();

    // Establish the session's password-hash baseline (Sanctum's
    // AuthenticateSession middleware records it on the first authenticated
    // request after login) — mirrors a real SPA calling /me right after
    // login to bootstrap its user state.
    Auth::forgetGuards();
    $this->withCookie($cookieName, $sessionId)->getJson('/api/auth/me')->assertOk();

    $token = captureResetToken('ann@example.com');
    $this->postJson('/api/auth/password/reset', [
        'email' => 'ann@example.com',
        'token' => $token,
        'password' => 'newpassword1',
    ])->assertOk();

    Auth::forgetGuards();
    $this->withCookie($cookieName, $sessionId)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});
