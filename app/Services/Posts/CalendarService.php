<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\PostGroup;
use App\Models\Tag;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model behind `GET /api/calendar` (Task 21). The calendar is the
 * screen users live in — called on every navigation with a date range
 * spanning weeks — so the query is written to cost a FIXED number of
 * round-trips regardless of how many groups/posts/channels fall in that
 * range:
 *
 *  1. one query for `post_groups` in range (with the tag/customer
 *     filters folded in as `WHERE EXISTS`/`whereIn` subqueries, not
 *     separate round-trips);
 *  2. one query for every `posts` row belonging to those groups
 *     (`WHERE post_group_id IN (...)`, Eloquent's standard eager-load
 *     batching);
 *  3. one query for every `integrations` row those posts reference
 *     (`WHERE id IN (...)`);
 *  4. one query for `tags` + the `post_group_tag` pivot together (a
 *     single joined query, not two, and not one-per-group).
 *
 * Four queries total for a month of posts across ten channels — the same
 * four queries whether the range holds five groups or five hundred.
 * `->with([...])` is what buys this: querying `$group->posts` inside a
 * loop instead (no eager load) would issue one query per group, and
 * `$post->integration` inside THAT loop would add one more per post — a
 * calendar screen doing dozens to hundreds of extra round-trips per page
 * load, exactly the N+1 shape this class exists to avoid.
 *
 * TAG FILTER / TENANT SAFETY: `tags[]` ids are resolved against
 * `Tag::query()` FIRST (organization-scoped via BelongsToOrganization's
 * global scope) before being used to filter groups. A tag id from
 * another organization therefore never reaches the group filter at all —
 * it resolves to nothing, and the whole request short-circuits to an
 * empty result, never "no tag filter applied" (which would silently
 * widen the response back to every group in range). See
 * tests/Feature/Posts/CalendarTest.php's
 * "does not widen the calendar result when a tag id belongs to another
 * organization" case.
 *
 * CUSTOMER FILTER: matches `integrations.customer_name` on ANY of a
 * group's posts' channels — a group is a calendar entry the moment one
 * of its channels belongs to the given customer, same "any segment"
 * semantics the tag filter uses. `Integration::query()` (reached via
 * `whereHas('posts.integration', ...)`) is itself organization-scoped,
 * so this filter can never match another organization's channels either.
 */
class CalendarService
{
    /**
     * @param  list<string>  $tagIds
     * @return Collection<int, PostGroup>
     */
    public function forRange(CarbonInterface $from, CarbonInterface $to, ?string $customer, array $tagIds): Collection
    {
        $query = PostGroup::query()
            ->whereBetween('publish_date', [$from, $to])
            ->with(['posts.integration', 'tags']);

        if (! $this->applyFilters($query, $customer, $tagIds)) {
            return new Collection;
        }

        return $query->orderBy('publish_date')->get();
    }

    /**
     * Block 2's list view: a page of groups instead of a date range, and
     * a lighter row shape (see PostGroupListResource).
     *
     * ORDERING. `publish_date DESC` is not a total order here, and rows
     * tied on it are the normal case rather than a corner case —
     * PostGroupService stamps `now()` on every group published
     * immediately and on every dateless draft. PostgreSQL may return
     * tied rows in either order per query, so a LIMIT/OFFSET boundary
     * falling inside a tie shows one group twice and hides another
     * entirely. `id` (a ULID: unique, and monotonic by creation time)
     * breaks every tie, which is what makes paging through this endpoint
     * stable rather than merely usually-correct.
     *
     * NULLS. `publish_date` is nullable, and PostgreSQL puts NULLs FIRST
     * on a DESC sort — so a group that somehow lost its date would head
     * a newest-first list. `nulls last` is spelled out rather than left
     * to the default: in practice PostGroupService always fills the
     * column in, and a list whose first row is the one broken record is
     * a bad way to discover that it did not.
     *
     * @param  list<string>  $tagIds
     * @return LengthAwarePaginator<int, PostGroup>
     */
    public function list(?PostState $state, ?string $customer, array $tagIds, int $perPage): LengthAwarePaginator
    {
        $query = PostGroup::query()
            ->with(['posts.integration', 'tags'])
            ->withCount('comments');

        if ($state !== null) {
            $query->where('state', $state->value);
        }

        if (! $this->applyFilters($query, $customer, $tagIds)) {
            // Same rule as forRange(): a tag id that resolves to nothing
            // in this organization must yield an empty result, never one
            // with the filter silently dropped. A false predicate rather
            // than an early return, so the caller still gets a real
            // paginator describing an empty page.
            $query->whereRaw('false');
        }

        return $query
            ->orderByRaw('publish_date desc nulls last')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The tag/customer filtering both readers share. Returns false when
     * the tag filter resolved to nothing in this organization — the
     * caller must then produce an empty result rather than an unfiltered
     * one (see this class's docblock, "TAG FILTER / TENANT SAFETY").
     *
     * Shared rather than copied: the rule it carries is a cross-tenant
     * one, and a second copy would be a second thing to keep true.
     *
     * @param  Builder<PostGroup>  $query
     * @param  list<string>  $tagIds
     */
    private function applyFilters(Builder $query, ?string $customer, array $tagIds): bool
    {
        if ($tagIds !== []) {
            // Resolve first, scoped to the current tenant — see this
            // class's docblock ("TAG FILTER / TENANT SAFETY").
            $resolvedTagIds = Tag::query()->whereIn('id', $tagIds)->pluck('id');

            if ($resolvedTagIds->isEmpty()) {
                return false;
            }

            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $resolvedTagIds));
        }

        if ($customer !== null) {
            $query->whereHas('posts.integration', fn ($q) => $q->where('customer_name', $customer));
        }

        return true;
    }
}
