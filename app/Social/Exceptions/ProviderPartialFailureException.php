<?php

namespace App\Social\Exceptions;

use App\Social\Dto\PostResultDto;
use RuntimeException;
use Throwable;

/**
 * A thread (multi-element $chain passed to post()) failed partway
 * through: one or more leading segments were successfully published to
 * the network before a later segment failed. Carries $published — the
 * results for every segment that DID succeed, keyed by the same chain
 * index post() would have used — so nothing already live on the
 * network is lost or silently re-posted.
 *
 * Providers must throw this instead of ProviderTransientException,
 * ProviderPermanentException, ProviderAuthException or
 * ProviderRateLimitException whenever $published is non-empty at the
 * point of failure — those four assume nothing was published yet.
 *
 * Pipeline obligation (Task 30): on catching this, persist a
 * PostResultDto (release_id/release_url) for every index present in
 * $published, mark only the remaining chain indices as failed, and
 * never retry/re-post a segment whose index already has a
 * $published entry — retrying the whole chain would duplicate it on
 * the network.
 */
class ProviderPartialFailureException extends RuntimeException implements ProviderException
{
    /**
     * @param  array<int, PostResultDto>  $published  Results for the chain indices that succeeded before the failure, keyed by chain index.
     */
    public function __construct(
        string $message,
        public readonly array $published,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
