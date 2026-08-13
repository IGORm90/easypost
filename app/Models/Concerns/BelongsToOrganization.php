<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current tenant (organization) and auto-fills
 * organization_id on create.
 *
 * Every model with an `organization_id` column must use this trait; a
 * direct `Model::find()`/query without it is forbidden project-wide (see
 * the Global Constraints in the implementation plan).
 *
 * Deviation from the plan's illustrative code: when no organization is
 * present in OrganizationContext, this scope excludes every row instead of
 * returning the query unfiltered. Returning everything would mean any code
 * path that forgets to set the context (a bug, a misconfigured job, a
 * console command) silently leaks every tenant's data instead of failing
 * loudly with zero rows. `withoutGlobalScopes()` remains the explicit,
 * intentional way to bypass this (used by the publish path and by tests),
 * so this does not block legitimate cross-tenant access — it only removes
 * the accidental, implicit kind.
 *
 * `organization()` now resolves for real: `App\Models\Organization` exists
 * as of Task 5.
 *
 * @property string|null $organization_id
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query): void {
            $organizationId = OrganizationContext::id();

            if ($organizationId !== null) {
                $query->where($query->getModel()->getTable().'.organization_id', $organizationId);

                return;
            }

            // Fail closed: no organization in context means no rows, not "all rows".
            $query->whereRaw('1 = 0');
        });

        static::creating(function ($model): void {
            $model->organization_id ??= OrganizationContext::id();
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
