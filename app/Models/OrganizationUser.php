<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The `organization_user` membership pivot: organization_id + user_id +
 * role, with its own ULID primary key (per the plan's data model).
 *
 * Deliberately does NOT use `BelongsToOrganization`, even though it has an
 * `organization_id` column. This table is what *establishes* tenant
 * membership; the places that need to read it — resolving which
 * organizations a user belongs to before any organization has been chosen
 * (see `ResolveOrganization` middleware), or checking `roleOf()` — must
 * work with no `OrganizationContext` set yet. `BelongsToOrganization`'s
 * global scope fails closed to zero rows when the context is empty, which
 * would make exactly those bootstrapping lookups always return nothing.
 * Because there is no automatic scope, every direct query against this
 * model that isn't already implicitly scoped by joining through a specific
 * Organization/User (e.g. `Organization::members()`) MUST go through
 * `::forOrganization($organization)` — never a raw `where('organization_id', ...)`
 * you have to remember to write correctly every time.
 *
 * `id` has no database default, which would ordinarily make
 * `BelongsToMany::attach()` unsafe (it normally inserts through the query
 * builder directly, bypassing model events entirely). That trap does NOT
 * apply here: `Organization::members()` is declared with
 * `->using(self::class)`, which makes `attach()` route through
 * `attachUsingCustomClass()` — a real `OrganizationUser::save()` per row,
 * so `HasUlids`' `creating` hook does fire and a ULID is generated even for
 * `$org->members()->attach($user, ['role' => $role])` with no explicit id
 * (verified directly against the database — see the covering test in
 * tests/Unit/OrganizationModelTest.php). Prefer `Organization::addMember()`
 * anyway: it's the more concise call and returns the created row instead of
 * void — not a workaround for a bug, just the nicer API.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property OrganizationRole $role
 */
class OrganizationUser extends Pivot
{
    /** @use HasFactory<OrganizationUserFactory> */
    use HasFactory, HasUlids;

    protected $table = 'organization_user';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'organization_id', 'user_id', 'role'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
        ];
    }

    /**
     * The required access path for tenant-scoped queries against this
     * table — see the class docblock.
     *
     * @param  Builder<OrganizationUser>  $query
     * @return Builder<OrganizationUser>
     */
    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where(
            'organization_id',
            $organization instanceof Organization ? $organization->id : $organization,
        );
    }
}
