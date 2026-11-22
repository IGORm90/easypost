<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Organizations\OrganizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Owns the "who does this OAuth callback belong to" decision for
 * SocialAuthController::callback() — a returning provider identity
 * (`provider` + `provider_user_id` already in `social_accounts`) resolves
 * straight to its linked user; a first-time one either creates a brand-new
 * user (+ their first organization, exactly like registration) or links
 * onto an existing local account by email, subject to the verified-email
 * policy in isEmailVerifiedByProvider().
 */
class SocialAccountService
{
    public function __construct(private readonly OrganizationService $organizations) {}

    public function findOrCreateUser(string $driver, SocialiteUser $socialiteUser): User
    {
        $existingAccount = SocialAccount::query()
            ->where('provider', $driver)
            ->where('provider_user_id', (string) $socialiteUser->getId())
            ->first();

        if ($existingAccount !== null) {
            $this->updateTokens($existingAccount, $socialiteUser);

            return $existingAccount->user;
        }

        // One transaction for the whole first-time-identity path: the
        // user, their organization (inside resolveUser()) and the
        // social_accounts row that points at them all commit together or
        // not at all. Without this, a failure on the SocialAccount::create()
        // insert below — a DB blip, or a concurrent duplicate racing the
        // (provider, provider_user_id) unique index — would leave a
        // committed user + organization that no social account links to:
        // a half-created signup, unrecoverable for the user (their next
        // attempt just hits the existing-email path instead of retrying
        // cleanly).
        return DB::transaction(function () use ($driver, $socialiteUser) {
            $user = $this->resolveUser($driver, $socialiteUser);

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $driver,
                'provider_user_id' => (string) $socialiteUser->getId(),
                'access_token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken,
                'token_expires_at' => $this->expiresAt($socialiteUser),
            ]);

            return $user;
        });
    }

    /**
     * Finds the local user this provider identity belongs to, or creates
     * one. Never returns a user whose email doesn't match what the
     * provider asserted, and never silently attaches a new provider
     * identity to somebody else's account — see
     * isEmailVerifiedByProvider() for the reasoning.
     */
    private function resolveUser(string $driver, SocialiteUser $socialiteUser): User
    {
        $email = $socialiteUser->getEmail();

        if ($email === null) {
            abort(422, 'This provider did not share an email address, so a local account cannot be created or matched.');
        }

        $email = Str::lower($email);
        $existingUser = User::firstWhere('email', $email);
        $emailVerified = $this->isEmailVerifiedByProvider($driver, $socialiteUser);

        if ($existingUser !== null) {
            // Central risk this guards against: a provider that lets
            // anyone assert an arbitrary, unverified email address would
            // otherwise let an attacker sign in as a victim's existing
            // account simply by typing the victim's address into that
            // provider's profile. Requiring the provider to have verified
            // the email closes that path; anything else is rejected
            // outright rather than silently creating a second account
            // (which `users.email` being unique would block anyway) or
            // silently linking (which is the actual takeover).
            if (! $emailVerified) {
                abort(409, 'An account with this email already exists. Log in with your password to link this provider from your account settings.');
            }

            return $existingUser;
        }

        // A first-time social login is a registration like any other, so the
        // same switch closes it. Existing users were handled above, and a
        // returning provider identity never reaches resolveUser() at all.
        abort_unless(config('auth.registration_enabled'), 403, 'Registration is closed.');

        // Already running inside findOrCreateUser()'s outer transaction
        // (which also covers the social_accounts insert), so this doesn't
        // open one of its own.
        //
        // `email_verified_at` is deliberately absent from User::$fillable
        // (see RegisterRequest's docblock and the matching RegistrationTest
        // case) precisely so no caller can mass-assign their way to a
        // pre-verified account. Setting it via markEmailAsVerified() below,
        // only when the provider itself vouched for the address, is what
        // makes this call trusted server-side code rather than user input.
        $user = User::create([
            'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname() ?? $email,
            'email' => $email,
            'password' => Str::password(40),
        ]);

        if ($emailVerified) {
            $user->markEmailAsVerified();
        }

        $this->organizations->createForUser($user, "{$user->name}'s workspace");

        return $user;
    }

    /**
     * Whether the provider itself vouches for this email address, not just
     * whether it returned one. This is a deliberately conservative,
     * per-driver policy rather than a blanket "trust the provider":
     *
     * - google / oidc: both are OIDC-based and carry the standard
     *   `email_verified` claim in their raw payload; trusted only when that
     *   claim is present and true.
     * - github: Socialite's GithubProvider (see
     *   vendor/laravel/socialite/src/Two/GithubProvider.php,
     *   getEmailByToken()) never exposes an `email_verified` claim, but it
     *   only ever populates `email` with the account's primary address
     *   *if* GitHub reports it `primary && verified` — an unverified email
     *   comes back as `null`. So on this driver, a non-null email already
     *   is the verification signal.
     *
     * Anything else (missing claim, unrecognised driver) defaults to
     * unverified — the safe failure mode for an account-takeover check.
     */
    private function isEmailVerifiedByProvider(string $driver, SocialiteUser $socialiteUser): bool
    {
        $raw = $socialiteUser->getRaw();

        if (array_key_exists('email_verified', $raw)) {
            return (bool) $raw['email_verified'];
        }

        return $driver === 'github' && $socialiteUser->getEmail() !== null;
    }

    private function updateTokens(SocialAccount $account, SocialiteUser $socialiteUser): void
    {
        $account->update([
            'access_token' => $socialiteUser->token,
            'refresh_token' => $socialiteUser->refreshToken,
            'token_expires_at' => $this->expiresAt($socialiteUser),
        ]);
    }

    private function expiresAt(SocialiteUser $socialiteUser): ?Carbon
    {
        return $socialiteUser->expiresIn !== null
            ? now()->addSeconds((int) $socialiteUser->expiresIn)
            : null;
    }
}
