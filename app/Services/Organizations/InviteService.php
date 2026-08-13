<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns invite creation and acceptance.
 */
class InviteService
{
    private const EXPIRES_IN_DAYS = 7;

    /**
     * Token length in characters. Str::random() draws from random_bytes()
     * (cryptographically secure, not derived from the email or any
     * sequential counter), so this is unguessable — not a ULID, which
     * deliberately encodes a timestamp and would make invite tokens
     * partially predictable.
     */
    private const TOKEN_LENGTH = 64;

    /**
     * Creates an invite for $organization. Delegates the "may $actor mint
     * an invite for $role" decision entirely to
     * OrganizationPolicy::inviteAs() — see that method's docblock for why
     * the SUPERADMIN case needs its own check beyond the route's
     * `role:ADMIN` gate.
     */
    public function create(Organization $organization, User $actor, ?string $email, OrganizationRole $role): Invite
    {
        Gate::forUser($actor)->authorize('inviteAs', [$organization, $role]);

        return Invite::create([
            'organization_id' => $organization->id,
            'email' => $email,
            'token' => $this->generateToken(),
            'role' => $role,
            'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
        ]);
    }

    /**
     * Accepts an invite by its bare token — deliberately looked up
     * unscoped (Invite::where('token', ...), not ::forOrganization()):
     * the whole point of this flow is that the invitee has no
     * organization membership, and therefore no OrganizationContext, yet.
     * See Invite's class docblock.
     *
     * Ordering of checks matters for the status codes the brief and the
     * task's guards require:
     *   1. token not found at all -> 404 (nothing to accept)
     *   2. already accepted -> 422 (single-use, business-state conflict)
     *   3. expired -> 410 (was valid once, no longer is)
     *   4. addressed to a different email than the caller -> 403
     *   5. caller already a member -> 422
     */
    public function accept(string $token, User $user): Invite
    {
        $invite = Invite::where('token', $token)->first();

        if (! $invite instanceof Invite) {
            abort(404, 'This invite does not exist.');
        }

        if ($invite->accepted_at !== null) {
            abort(422, 'This invite has already been accepted.');
        }

        if ($invite->expires_at->isPast()) {
            abort(410, 'This invite has expired.');
        }

        if ($invite->email !== null && Str::lower($invite->email) !== Str::lower($user->email)) {
            abort(403, 'This invite was addressed to a different email address.');
        }

        $organization = $invite->organization;

        if ($organization->roleOf($user) !== null) {
            throw ValidationException::withMessages([
                'invite' => ['You are already a member of this organization.'],
            ]);
        }

        DB::transaction(function () use ($invite, $organization, $user) {
            $organization->addMember($user, $invite->role);
            $invite->forceFill(['accepted_at' => now()])->save();
        });

        return $invite->fresh(['organization']);
    }

    /**
     * Loops on collision (astronomically unlikely at 64 random chars, but
     * cheap to guard against outright rather than rely on the unique index
     * alone surfacing as an unhandled 500).
     */
    private function generateToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (Invite::where('token', $token)->exists());

        return $token;
    }
}
