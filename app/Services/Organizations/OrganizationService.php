<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

class OrganizationService
{
    /**
     * Creates a new organization owned by $user and makes them its first
     * member with the SUPERADMIN role, via Organization::addMember() (the
     * only safe way to create an organization_user row — see its
     * docblock). Callers (e.g. AuthController::register) are expected to
     * wrap this in DB::transaction() alongside the user's own creation, so
     * a failure never leaves a user without a workspace or an organization
     * without an owning member.
     */
    public function createForUser(User $user, string $name): Organization
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'owner_id' => $user->id,
        ]);

        $organization->addMember($user, OrganizationRole::SUPERADMIN);

        return $organization;
    }

    /**
     * Updates the current organization's settings. Only {name,
     * shortlink_provider} are ever accepted here (see
     * UpdateOrganizationRequest::rules()) — mass-assignment-safe the same
     * way RegisterRequest is for User::create().
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(Organization $organization, array $data): Organization
    {
        $organization->update($data);

        return $organization;
    }

    /**
     * Appends a short random suffix so two organizations with the same
     * human-readable name (e.g. two users both named "Ann") never collide
     * on the `slug` unique index.
     */
    private function uniqueSlug(string $name): string
    {
        return Str::slug($name).'-'.Str::lower(Str::random(6));
    }
}
