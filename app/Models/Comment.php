<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment left on a post group (Task 22 builds the endpoint; this task
 * lays the schema only).
 *
 * Deliberately does NOT use `BelongsToOrganization`: this table has no
 * `organization_id` column. Tenant scoping is inherited transitively —
 * every row is only ever reached through `PostGroup::comments()`, and
 * `PostGroup` is itself organization-scoped — the same reasoning
 * `IntegrationTimeSlot` documents for its relationship to `Integration`.
 *
 * @property string $id
 * @property string $post_group_id
 * @property string $user_id
 * @property string $content
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['post_group_id', 'user_id', 'content'];

    /**
     * @return BelongsTo<PostGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PostGroup::class, 'post_group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
