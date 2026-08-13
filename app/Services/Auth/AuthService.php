<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns the credential-matching, rate-limiting and session lifecycle rules
 * for POST /api/auth/{login,logout} — none of this belongs in
 * AuthController, whose job is only to call this and shape a response.
 */
class AuthService
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * A fixed, valid bcrypt hash of a random value nobody holds the
     * plaintext for. When the email doesn't match a user, Hash::check() is
     * still run against this instead of being skipped — skipping it would
     * make the "unknown email" path measurably faster than the "wrong
     * password" path, letting an attacker enumerate registered emails by
     * timing alone.
     */
    private const DUMMY_HASH = '$2y$12$R7DjdPXTc6vrI3aa965W2ezIG.2IY48wX.AGU.Eqrng2n2AWV8fXC';

    /**
     * Verifies the given credentials and returns the matching user.
     *
     * Throws the same ValidationException — same field, same message,
     * same 422 — whether the email doesn't exist or the password is
     * wrong, so a caller can't distinguish "no such account" from "wrong
     * password". Throws a 429 ValidationException instead once the
     * per-email-per-IP attempt budget is exhausted, even for correct
     * credentials, so a lockout can't be raced by finally guessing right.
     */
    public function attemptLogin(string $email, string $password, string $ip): User
    {
        $email = Str::lower($email);
        $throttleKey = $this->throttleKey($email, $ip);

        // Hit-then-check, not check-then-hit: RateLimiter::hit() is a
        // single atomic cache increment, and the threshold decision below
        // uses that call's own return value — the up-to-date count that
        // already includes this request. The previous tooManyAttempts()
        // (read) ... Hash::check() (slow) ... hit() (write, failure-only)
        // sequence left a window where concurrent requests could each read
        // a stale "not yet at the limit" count before any of them recorded
        // a hit, letting a burst slip past MAX_ATTEMPTS.
        $attempts = RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        if ($attempts > self::MAX_ATTEMPTS) {
            $this->throwLockoutException($throttleKey);
        }

        $user = User::firstWhere('email', $email);

        // Ternary, not `$user?->password ?? self::DUMMY_HASH`: the
        // nullsafe form still reads cleanly, but PHPStan's `nullsafe.neverNull`
        // rule flags it as unnecessary here (it only cares whether the
        // expression's *value* changes, and a plain `->` read on null also
        // evaluates to null in PHP 8 rather than throwing) — while PHP
        // itself still emits "Attempt to read property on null" as a
        // real warning on every request against an unregistered email,
        // the single most security-sensitive path in this method. This
        // form reaches level 6 clean and never evaluates `->password`
        // when $user is null, so neither problem applies.
        $hashedPassword = $user === null ? self::DUMMY_HASH : $user->password;
        $passwordMatches = Hash::check($password, $hashedPassword);

        if (! $user || ! $passwordMatches) {
            $this->throwInvalidCredentialsException();
        }

        RateLimiter::clear($throttleKey);

        return $user;
    }

    /**
     * Logs the user into the 'web' session guard and regenerates the
     * session id, closing the session-fixation window (a pre-login
     * session id, e.g. one an attacker planted, is never reused
     * post-login).
     */
    public function login(Request $request, User $user): void
    {
        Auth::login($user);
        $request->session()->regenerate();
    }

    /**
     * Ends the session: logs out the guard, invalidates the session data
     * and id, and rotates the CSRF token — so neither the old session id
     * nor its CSRF token remain valid after logout.
     */
    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function throttleKey(string $email, string $ip): string
    {
        return "login:{$email}|{$ip}";
    }

    private function throwLockoutException(string $throttleKey): never
    {
        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
        ])->status(429);
    }

    private function throwInvalidCredentialsException(): never
    {
        throw ValidationException::withMessages([
            'email' => ['These credentials do not match our records.'],
        ]);
    }
}
