<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Owns the rules for GET /api/auth/email/verify/{id}/{hash} and
 * POST /api/auth/email/resend — kept out of EmailVerificationController for
 * the same layering reason AuthService is kept out of AuthController.
 */
class EmailVerificationService
{
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 300;

    /**
     * The `signed` route middleware already guarantees the {id, hash} pair
     * hasn't expired and hasn't been tampered with (the signature covers
     * every route parameter, including `id`). What it can't guarantee is
     * that `hash` still matches the user's *current* email — if the user
     * changed their address after the link was issued, the old link must
     * stop working even though its signature is still technically valid.
     * That's what this explicit comparison catches.
     *
     * Verifying an already-verified user is a no-op, not an error — a
     * clicked-twice link (e.g. an email client prefetching it) shouldn't
     * surface as a failure to the user.
     */
    public function verify(string $id, string $hash): void
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'This verification link is invalid.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }

    /**
     * Rate-limited the same way AuthService::attemptLogin() rate-limits
     * login: keyed by user + IP, hit-then-check so the increment and the
     * threshold decision use the same atomic read. Unthrottled, this
     * endpoint is a mail-bomb primitive aimed at whatever address the
     * caller is authenticated as.
     */
    public function resend(User $user, string $ip): void
    {
        $throttleKey = $this->throttleKey($user, $ip);
        $attempts = RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        if ($attempts > self::MAX_ATTEMPTS) {
            $this->throwLockoutException($throttleKey);
        }

        $user->sendEmailVerificationNotification();
    }

    private function throttleKey(User $user, string $ip): string
    {
        return "verify-resend:{$user->id}|{$ip}";
    }

    private function throwLockoutException(string $throttleKey): never
    {
        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => ["Too many verification emails requested. Please try again in {$seconds} seconds."],
        ])->status(429);
    }
}
