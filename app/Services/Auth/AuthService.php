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

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $this->throwLockoutException($throttleKey);
        }

        $user = User::firstWhere('email', $email);

        $passwordMatches = Hash::check($password, $user->password ?? self::DUMMY_HASH);

        if (! $user || ! $passwordMatches) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
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
