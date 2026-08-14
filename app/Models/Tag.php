<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An organization-scoped label attachable to any number of post groups
 * (Task 23 builds the endpoints; this task lays the schema only).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $color
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'name', 'color'];

    /**
     * @return BelongsToMany<PostGroup, $this>
     */
    public function postGroups(): BelongsToMany
    {
        return $this->belongsToMany(PostGroup::class, 'post_group_tag');
    }
}
