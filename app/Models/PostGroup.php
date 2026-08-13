<?php

namespace App\Models;

use App\Enums\PostState;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\PostGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The calendar-level entity a user schedules: one publish date/time and
 * one lifecycle state spanning every channel (`Post` segment) it was
 * scheduled to. Task 18 lays the schema and relations only — validation
 * (Task 19), the CRUD API (Task 20), the calendar (Task 21), and
 * comments/sharing (Task 22) are later tasks.
 *
 * `state` shares `PostState`/`PostStateMachine` with `Post` — see that
 * class's docblock for the transition table and why `PUBLISHED` is
 * terminal.
 *
 * @property string $id
 * @property string $organization_id
 * @property PostState $state
 * @property CarbonInterface|null $publish_date
 * @property int $queue_version
 * @property string|null $created_by
 * @property string|null $error
 * @property string|null $share_token
 */
class PostGroup extends Model
{
    /** @use HasFactory<PostGroupFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'state',
        'publish_date',
        'created_by',
        'error',
        'share_token',
        'queue_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => PostState::class,
            'publish_date' => 'datetime',
            'queue_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->orderBy('order');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_group_tag');
    }
}
