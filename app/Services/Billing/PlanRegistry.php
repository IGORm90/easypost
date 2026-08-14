<?php

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Models\Organization;

/**
 * Resolves the plan (name + limits, straight out of `config/plans.php`)
 * that applies to a given organization right now.
 *
 * This is the single seam a future billing system would replace. Today,
 * `organizations.plan` is set by hand or via `php artisan org:plan` — no
 * Stripe, no Cashier, no subscription/invoice concept anywhere in this
 * codebase, deliberately. If payments are ever added, only
 * `forOrganization()`'s body changes (e.g. to consult a subscription
 * record instead of the bare column); every other caller in this
 * application already goes through `App\Services\Billing\PlanLimitService`,
 * which itself only ever calls this method — nothing else reads
 * `organizations.plan` directly.
 */
class PlanRegistry
{
    /**
     * @return array{name: string, limits: array{channels: int|null, posts_per_month: int|null, media_storage_mb: int|null}}
     */
    public function forOrganization(Organization $organization): array
    {
        /** @var array<string, array{name: string, limits: array{channels: int|null, posts_per_month: int|null, media_storage_mb: int|null}}> $plans */
        $plans = config('plans');

        // `$organization->plan` is cast via `App\Casts\PlanCast`, which
        // resolves an unrecognised or missing raw value to `null` rather
        // than throwing — see that class's docblock for why. Both "no
        // plan recorded" and "a value this application no longer
        // recognises" collapse to the same fallback here: the free plan.
        $plan = $organization->plan ?? Plan::FREE;

        return $plans[$plan->value] ?? $plans[Plan::FREE->value];
    }
}
