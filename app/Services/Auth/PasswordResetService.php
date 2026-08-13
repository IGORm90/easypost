<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns the rules for POST /api/auth/password/{forgot,reset} — kept out of
 * PasswordResetController for the same layering reason AuthService is kept
 * out of AuthController.
 */
class PasswordResetService
{
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 60;

    /**
     * Always attempts Password::sendResetLink() but never surfaces its
     * result (Password::RESET_LINK_SENT vs Password::INVALID_USER) to the
     * caller — PasswordResetController returns the exact same response
     * regardless. Mirrors AuthService::attemptLogin()'s anti-enumeration
     * design: an unknown email must not be observably different from a
     * known one.
     *
     * Rate-limited the same way (hit-then-check, keyed by email + IP) so
     * this can't be used to mail-bomb a third party's inbox. The throttle
     * key doesn't distinguish known from unknown emails either, so hitting
     * the limit doesn't leak anything a caller couldn't already probe.
     */
    public function sendResetLink(string $email, string $ip): void
    {
        $throttleKey = $this->throttleKey(Str::lower($email), $ip);
        $attempts = RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        if ($attempts > self::MAX_ATTEMPTS) {
            $this->throwLockoutException($throttleKey);
        }

        Password::sendResetLink(['email' => $email]);
    }

    /**
     * Consumes the token via Laravel's password broker, which — on success
     * — deletes the token row before returning (Password::PASSWORD_RESET),
     * making it single-use: a replay of the same token afterwards gets
     * Password::INVALID_TOKEN and fails here.
     *
     * Deliberately does not delete rows from the `sessions` table to "log
     * out other devices": in production SESSION_DRIVER is redis, so that
     * table isn't even where sessions live. Instead this relies on
     * Sanctum's AuthenticateSession middleware (already active — see
     * bootstrap/app.php's statefulApi() from Task 6, which pulls in
     * Sanctum's default `authenticate_session` middleware), which stores a
     * hash of the session's password at the time it was established and
     * compares it against the current one on every subsequent authenticated
     * request; once forceFill() below changes the hash, any other session
     * still carrying the old one is rejected and flushed on its next use.
     * This works identically regardless of session driver.
     */
    public function reset(string $email, string $token, string $password): void
    {
        $status = Password::reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user) use ($password): void {
                $user->forceFill(['password' => $password])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This password reset token is invalid or has expired.'],
            ]);
        }
    }

    private function throttleKey(string $email, string $ip): string
    {
        return "password-forgot:{$email}|{$ip}";
    }

    private function throwLockoutException(string $throttleKey): never
    {
        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => ["Too many password reset requests. Please try again in {$seconds} seconds."],
        ])->status(429);
    }
}
