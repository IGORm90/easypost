<?php

namespace App\Services\Mail;

use Carbon\CarbonInterface;

/**
 * The result of a successful `DigestService::claim()` — carries the
 * user's `digest_sent_at` value as it stood immediately BEFORE this
 * claim overwrote it, so `buildDigest()` can anchor the content window
 * on the actual previous send instant rather than recomputing "7 days
 * ago" from scratch (see `DigestService::buildDigest()`'s docblock for
 * why that distinction matters across a DST transition). `null` means
 * this is the user's first-ever digest.
 */
final readonly class DigestClaim
{
    public function __construct(
        public ?CarbonInterface $previousDigestSentAt,
    ) {}
}
