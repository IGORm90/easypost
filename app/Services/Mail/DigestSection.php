<?php

namespace App\Services\Mail;

use App\Models\PostGroup;
use Illuminate\Database\Eloquent\Collection;

/**
 * One category ("published", "failed", "scheduled") inside a single
 * user's weekly digest — see `DigestService::buildDigest()`.
 *
 * `$total` can exceed `count($items)`: `DigestService::LIST_LIMIT` bounds
 * how many rows any one digest email actually lists (an unattended
 * cross-tenant query with no cap would let one very active organization's
 * backlog make a single email unboundedly large — the same "this runs
 * unattended, forever" reasoning `MissedPostsService::CHUNK_SIZE` documents),
 * so `hasMore()`/`moreCount()` let the mail view render an honest "…and N
 * more" line instead of silently truncating.
 */
final readonly class DigestSection
{
    /**
     * @param  Collection<int, PostGroup>  $items  Already capped at `DigestService::LIST_LIMIT` and eager-loaded with `organization`.
     */
    public function __construct(
        public Collection $items,
        public int $total,
    ) {}

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function hasMore(): bool
    {
        return $this->total > $this->items->count();
    }

    public function moreCount(): int
    {
        return max(0, $this->total - $this->items->count());
    }
}
