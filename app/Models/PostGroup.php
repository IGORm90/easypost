<?php

namespace App\Models;

use App\Enums\PostState;
use App\Enums\RepeatUnit;
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
 * `creator` is annotated as nullable below because `created_by` is
 * `nullOnDelete()`: an offboarded author leaves the group behind with no
 * creator at all, and every reader of `$group->creator` has to keep
 * handling that.
 *
 * @property string $id
 * @property string $organization_id
 * @property PostState $state
 * @property CarbonInterface|null $publish_date
 * @property int $queue_version
 * @property CarbonInterface|null $last_dispatched_at
 * @property int $dispatch_attempts
 * @property string|null $created_by
 * @property string|null $error
 * @property CarbonInterface|null $notified_at
 * @property string|null $share_token
 * @property RepeatUnit|null $repeat_unit
 * @property int|null $repeat_interval
 * @property CarbonInterface|null $repeat_until
 * @property string|null $repeat_parent_id
 * @property CarbonInterface|null $repeat_spawned_at
 * @property User|null $creator
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
        'notified_at',
        'share_token',
        'queue_version',
        'last_dispatched_at',
        'dispatch_attempts',
        'repeat_unit',
        'repeat_interval',
        'repeat_until',
        'repeat_parent_id',
        'repeat_spawned_at',
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
            'notified_at' => 'datetime',
            'last_dispatched_at' => 'datetime',
            'dispatch_attempts' => 'integer',
            'repeat_unit' => RepeatUnit::class,
            'repeat_interval' => 'integer',
            'repeat_until' => 'datetime',
            'repeat_spawned_at' => 'datetime',
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
     * The FIRST group of this recurring series — never the immediate
     * predecessor. See the recurrence migration for why: it makes the
     * whole series one query instead of a walk.
     *
     * @return BelongsTo<PostGroup, $this>
     */
    public function repeatParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repeat_parent_id');
    }

    /**
     * Every other occurrence of the series this group is the root of.
     * Only ever populated on the root, by construction.
     *
     * @return HasMany<PostGroup, $this>
     */
    public function repeatChildren(): HasMany
    {
        return $this->hasMany(self::class, 'repeat_parent_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_group_tag');
    }
}
