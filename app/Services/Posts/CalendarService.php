<?php

namespace App\Services\Posts;

use App\Models\PostGroup;
use App\Models\Tag;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

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

        if ($tagIds !== []) {
            // Resolve first, scoped to the current tenant — see this
            // class's docblock ("TAG FILTER / TENANT SAFETY").
            $resolvedTagIds = Tag::query()->whereIn('id', $tagIds)->pluck('id');

            if ($resolvedTagIds->isEmpty()) {
                return new Collection;
            }

            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $resolvedTagIds));
        }

        if ($customer !== null) {
            $query->whereHas('posts.integration', fn ($q) => $q->where('customer_name', $customer));
        }

        return $query->orderBy('publish_date')->get();
    }
}
