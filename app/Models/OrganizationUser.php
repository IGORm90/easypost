<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationUserFactory;
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
}
