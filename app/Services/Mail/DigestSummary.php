<?php

namespace App\Services\Mail;

/**
 * The full content of one user's weekly digest — see
 * `DigestService::buildDigest()`. Three independent `DigestSection`s,
 * each already scoped to precisely the organizations the recipient
 * belongs to (see `DigestService`'s class docblock for the security
 * reasoning).
 */
final readonly class DigestSummary
{
    public function __construct(
        public DigestSection $published,
        public DigestSection $failed,
        public DigestSection $scheduled,
    ) {}

    public function isEmpty(): bool
    {
        return $this->published->isEmpty() && $this->failed->isEmpty() && $this->scheduled->isEmpty();
    }
}
