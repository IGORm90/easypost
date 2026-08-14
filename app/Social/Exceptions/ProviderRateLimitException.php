<?php

namespace App\Social\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The remote network rejected the call because a rate limit was hit.
 * Distinct from ProviderTransientException because the pipeline (Task
 * 30) must wait at least $retryAfterSeconds — not its usual short
 * backoff — before retrying, honouring whatever the network itself
 * communicated (e.g. a Retry-After header or a rate-limit response
 * field).
 *
 * If some leading segments of the chain passed to post() already
 * published successfully before the rate limit was hit, throw
 * ProviderPartialFailureException instead — those segments must be
 * persisted, not silently lost by failing the whole post.
 */
class ProviderRateLimitException extends RuntimeException implements ProviderException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
