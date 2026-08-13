<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Deliberately does NOT use `BelongsToOrganization` despite having an
 * `organization_id` column, for the same bootstrapping reason as
 * `OrganizationUser` (see that class's docblock): accepting an invite
 * happens via a bare token, before the invitee has any membership — and
 * therefore no `OrganizationContext` — at all. Scoping invite lookups to
 * the current tenant would make `Invite::where('token', $token)->first()`
 * always return null during the accept flow.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $email
 * @property string $token
 * @property OrganizationRole $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'email', 'token', 'role', 'expires_at', 'accepted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
