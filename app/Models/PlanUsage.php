<?php

namespace App\Models;

use Database\Factories\PlanUsageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached, per-organization, per-calendar-month counter for exactly one
 * metric: `PlanLimit::POSTS_PER_MONTH`. Owned entirely by
 * `App\Services\Billing\PlanLimitService` — nothing else should read or
 * write this table directly. See that class and the `plan_usages`
 * migration for why only this one metric is cached here at all.
 *
 * Deliberately does NOT use `BelongsToOrganization`: every query against
 * this table goes through `PlanLimitService`, always scoped by the
 * `Organization` instance it was explicitly called with, never by the
 * ambient `OrganizationContext` — `check()`/`usage()` must give correct
 * answers for whatever organization the caller passed in, including from
 * a console command or job where no per-request tenant context exists at
 * all (the same reasoning `Post`'s docblock gives for its own denormalised
 * `organization_id`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $metric
 * @property string $period
 * @property int $count
 */
class PlanUsage extends Model
{
    /** @use HasFactory<PlanUsageFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'metric',
        'period',
        'count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'count' => 'integer',
        ];
    }
}
