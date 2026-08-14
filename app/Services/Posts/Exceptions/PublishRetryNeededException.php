<?php

namespace App\Services\Posts\Exceptions;

use RuntimeException;

/**
 * Internal signal, never a user-facing error: thrown by
 * `PublishService::publishGroup()` when at least one channel in the
 * group hit a `ProviderTransientException`, `ProviderRateLimitException`,
 * or `ProviderPartialFailureException` and must be attempted again later.
 *
 * Deliberately thrown AFTER every DB write `publishGroup()` made for
 * this run has already committed (see that method's docblock) — this
 * exception carries no rollback semantics of its own, it only exists to
 * cross the boundary from the service (which has no queue/job access)
 * back to `PublishPostJob::handle()` (which does), so the job can call
 * `$this->release()` with the right delay. Nothing in `PublishService`
 * catches this; nothing outside `PublishPostJob` should either.
 *
 * $retryAfterSeconds is null unless a `ProviderRateLimitException` was
 * the (or one of the) reason(s) for the retry, in which case it carries
 * the SMALLEST `retryAfterSeconds` seen across every channel that needed
 * one this run — waiting at least as long as every provider that asked
 * for a wait requested. Null means "no channel gave a specific delay";
 * the job falls back to its own fixed backoff schedule for that case.
 */
class PublishRetryNeededException extends RuntimeException
{
    public function __construct(
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('One or more channels need to be retried before this post group can be finalized.');
    }
}
