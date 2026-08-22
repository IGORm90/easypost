<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as the instance-wide admin screen sees them (Task 1.7).
 *
 * Deliberately a separate resource from UserResource rather than an
 * extension of it. UserResource's docblock calls its array "the entire
 * contract of what this endpoint can ever return", and
 * tests/Feature/Auth/RegistrationTest.php asserts `user.is_superadmin` is
 * absent from it — that flag is an instance-level privilege that has no
 * business travelling with every /auth/me and every login response. Here
 * it is the point: the one screen that can grant an impersonation session
 * has to show who else already holds the privilege.
 *
 * Only ever reachable behind the `superadmin` middleware, which is why
 * listing other people's addresses and memberships is acceptable at all.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_superadmin' => $this->is_superadmin,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            // Names only. The admin list answers "who is this person"
            // well enough with the workspaces they belong to; ids and
            // roles would be a second screen's worth of detail, and this
            // one is a picker for impersonation, not a tenancy audit.
            'organizations' => $this->organizations->pluck('name')->values(),
        ];
    }
}
