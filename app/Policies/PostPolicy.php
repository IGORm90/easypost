<?php

namespace App\Policies;

use App\Models\PostGroup;
use App\Models\User;

/**
 * Authorization for a single organization's post groups (Task 20). Named
 * `PostPolicy` per the brief rather than `PostGroupPolicy` — Laravel's
 * naming-convention auto-discovery would otherwise bind it to `PostGroup`
 * automatically, so `AppServiceProvider::boot()` registers it explicitly
 * via `Gate::policy(PostGroup::class, PostPolicy::class)` instead.
 *
 * Deliberately no role bar beyond plain membership (unlike
 * IntegrationPolicy::manage(), which requires ADMIN): scheduling and
 * editing posts is core, everyday content work for any member of the
 * organization, not an administrative action. Cross-tenant access to a
 * post group by id is handled separately by route-model resolution (a
 * BelongsToOrganization-scoped lookup 404s a wrong-organization id before
 * this policy is ever consulted) — this class only answers "is $user a
 * member of $group's organization at all".
 */
class PostPolicy
{
    public function view(User $user, PostGroup $group): bool
    {
        return $group->organization->roleOf($user) !== null;
    }

    public function manage(User $user, PostGroup $group): bool
    {
        return $group->organization->roleOf($user) !== null;
    }
}
