<?php

namespace App\Services\Posts;

use App\Models\PostGroup;

/**
 * What PostGroupLifecycle::edit() hands back: the fresh group, the
 * `queue_version` it ended at, and whether any dispatch that follows must
 * force a new job past an older generation's uniqueness lock. The caller
 * dispatches AFTER its own transaction has committed, never inside it.
 */
final readonly class Receipt
{
    public function __construct(
        public PostGroup $group,
        public int $queueVersion,
        public bool $forced = false,
    ) {}
}
