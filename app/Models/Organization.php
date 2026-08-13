<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $owner_id
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'owner_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationUser>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->using(OrganizationUser::class)
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * Returns the caller's role in this organization, or null if they are
     * not a member.
     *
     * Query-cost decision, deliberate: if `members` is already eager-loaded
     * (e.g. an authorization check iterating a list of members that was
     * already fetched with `Organization::with('members')`), this reads
     * from the loaded in-memory collection — zero additional queries, so
     * calling roleOf() in a loop over a preloaded membership list does not
     * N+1. Otherwise — the common case, e.g. a single per-request
     * authorization check — it runs exactly one targeted query against
     * organization_user (via the `members` relation's pivot join), scoped
     * to this one user, rather than loading the whole membership list just
     * to inspect one row.
     */
    public function roleOf(User $user): ?OrganizationRole
    {
        if ($this->relationLoaded('members')) {
            /** @var (User&object{pivot: OrganizationUser})|null $member */
            $member = $this->members->firstWhere('id', $user->id);

            return $member?->pivot instanceof OrganizationUser ? $member->pivot->role : null;
        }

        /** @var (User&object{pivot: OrganizationUser})|null $member */
        $member = $this->members()->wherePivot('user_id', $user->id)->first();

        return $member?->pivot instanceof OrganizationUser ? $member->pivot->role : null;
    }
}
