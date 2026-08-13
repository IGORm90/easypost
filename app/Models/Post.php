<?php

namespace App\Models;

use App\Enums\PostState;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One segment of a post group destined for exactly one channel
 * (`Integration`). A "thread" is the chain of segments on the same
 * channel linked root-to-tip via `parent_post_id`, ordered by `order`.
 *
 * `organization_id` is denormalised onto this table rather than reached
 * only through `post_group_id` — see the `posts` migration's class
 * docblock for the full reasoning (BelongsToOrganization needs a real
 * column to scope by, and Task 30's publish job queries posts with no
 * organization in `OrganizationContext` at all, the same shape Task 16
 * solved for `Integration`). The composite foreign key added in that
 * migration is what stops this column from ever drifting from its
 * group's real organization_id.
 *
 * `release_id` is the anti-duplication marker described in Task 15's
 * `ProviderPartialFailureException`/`PostResultDto` contract: once a
 * provider confirms a segment went out, `release_id` is set and — per
 * the `posts_release_id_immutable` trigger — can never be changed or
 * cleared again by any UPDATE, database-enforced rather than merely
 * assumed by the (not-yet-built) publish pipeline.
 *
 * @property string $id
 * @property string $post_group_id
 * @property string $organization_id
 * @property string $integration_id
 * @property string|null $parent_post_id
 * @property int $order
 * @property string $content
 * @property array<string, mixed>|null $settings
 * @property PostState $state
 * @property string|null $release_url
 * @property string|null $release_id
 * @property string|null $error
 * @property CarbonInterface|null $published_at
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /**
     * The custom Postgres SQLSTATE the `posts_release_id_immutable`
     * trigger (see the `posts` migration) raises with, instead of the
     * generic 'P0001' every unqualified PL/pgSQL `raise exception`
     * shares. This is the single source of truth for that code — the
     * migration's SQL and any future caller that needs to detect "this
     * failed specifically because of the immutability guard" (Task 30,
     * most likely, via `Illuminate\Database\QueryException::getCode()`)
     * both read it from here rather than maintaining two independent
     * copies of the same literal.
     */
    public const string RELEASE_ID_IMMUTABLE_SQLSTATE = 'PS001';

    /** @var list<string> */
    protected $fillable = [
        'post_group_id',
        'organization_id',
        'integration_id',
        'parent_post_id',
        'order',
        'content',
        'settings',
        'state',
        'release_url',
        'release_id',
        'error',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'settings' => 'array',
            'state' => PostState::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PostGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PostGroup::class, 'post_group_id');
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'parent_post_id');
    }

    /**
     * Direct children in this segment's thread, ordered by `order`. The
     * database — not merely this relation — guarantees a returned child
     * shares this post's `post_group_id` and `integration_id`: see the
     * `posts_parent_same_group_and_integration_fk` composite foreign key
     * in the `posts` migration.
     *
     * @return HasMany<Post, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Post::class, 'parent_post_id')->orderBy('order');
    }
}
