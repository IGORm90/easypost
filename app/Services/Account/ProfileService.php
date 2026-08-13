<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Owns the profile-editing and API-key rotation rules for
 * PUT /api/profile and POST /api/profile/api-key/rotate — kept out of
 * ProfileController, whose job is only to call this and shape a response.
 */
class ProfileService
{
    private const API_KEY_LENGTH = 64;

    /**
     * @param  array{name: string, timezone: string, locale: string, email_notifications: bool}  $data
     */
    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user;
    }

    /**
     * Generates a new cryptographically random API key, stores only its
     * SHA-256 hash, and returns the plaintext — the one and only time it
     * is ever available, to caller or to us. See task-10-report.md for why
     * plaintext is never persisted: the key authenticates a bearer for
     * later (post-MVP) API access, exactly the same threat model as a
     * Sanctum personal access token, and Sanctum itself only ever stores
     * hash('sha256', $token) for that reason — a leaked `users` row (a
     * backup, a read replica, a careless `SELECT *` logged somewhere) must
     * not itself be a usable credential. SHA-256 rather than bcrypt: this
     * is a 64-byte-of-entropy random token, not a low-entropy
     * human-chosen password, so there is no offline-guessing risk a slow
     * hash defends against, and paying bcrypt's cost on every API request
     * that authenticates with this key would be pure overhead.
     *
     * `Str::random()` is Laravel's CSPRNG-backed generator (random_bytes()
     * under the hood), not a plain uniqid()/mt_rand() style generator.
     */
    public function rotateApiKey(User $user): string
    {
        $plaintext = Str::random(self::API_KEY_LENGTH);

        $user->forceFill(['api_key' => hash('sha256', $plaintext)])->save();

        return $plaintext;
    }
}
