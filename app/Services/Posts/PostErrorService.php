<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\PostGroup;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model behind `GET /api/posts/errors` (Task 32) — the failed-
 * publications feed a calendar UI banner reads. Runs through the
 * model's normal, scoped query builder (this is a request-time call
 * with a real `OrganizationContext` set, unlike `MissedPostsService`) —
 * `BelongsToOrganization`'s global scope does the tenant filtering here,
 * exactly like `CalendarService::forRange()`, this class's closest
 * sibling.
 *
 * Filtered on `publish_date`, the same column the calendar itself
 * renders by (see `PostErrorsRequest`'s docblock) — this is "which
 * scheduled posts in this range ended up failed", not "which groups
 * happened to fail during this range" (`updated_at` would answer that
 * different question).
 */
class PostErrorService
{
    /**
     * @return Collection<int, PostGroup>
     */
    public function forRange(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return PostGroup::query()
            ->where('state', PostState::ERROR->value)
            ->whereBetween('publish_date', [$from, $to])
            ->with(['posts.integration'])
            ->orderByDesc('publish_date')
            ->get();
    }
}
