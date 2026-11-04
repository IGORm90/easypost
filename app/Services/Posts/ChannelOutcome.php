<?php

namespace App\Services\Posts;

use App\Models\Integration;
use App\Social\Dto\PostResultDto;

/**
 * What ChannelAttempt found out: a verdict, not an action. It carries what
 * the lifecycle needs to RECORD the attempt — which segments went out and with
 * what result, what to write on the ones that failed, how long to wait before
 * trying again, and whether the channel's credentials need re-connecting — but
 * writes none of it.
 *
 * `published` is keyed by the segment's position in the pending chain.
 * `failureKind` is the short reason the attempt log reports (`partial`,
 * `rate_limit`, `transient`, `media`, `permanent`, `auth`, `unknown_provider`),
 * null when there is none. `integration` is null only when the channel no longer
 * exists, in which case there is nothing to log an attempt against.
 */
final readonly class ChannelOutcome
{
    /**
     * @param  array<int, PostResultDto>  $published
     */
    private function __construct(
        public ChannelOutcomeKind $kind,
        public ?Integration $integration,
        public ?string $failureKind,
        public array $published = [],
        public ?string $errorMessage = null,
        public ?int $retryAfterSeconds = null,
        public bool $flagsRefresh = false,
    ) {}

    /**
     * @param  array<int, PostResultDto>  $published
     */
    public static function published(Integration $integration, array $published): self
    {
        return new self(ChannelOutcomeKind::Published, $integration, null, $published);
    }

    /**
     * @param  array<int, PostResultDto>  $published  segments that did go out before the retry was needed
     */
    public static function retry(Integration $integration, ?string $failureKind, array $published = [], ?int $retryAfterSeconds = null): self
    {
        return new self(ChannelOutcomeKind::Retry, $integration, $failureKind, $published, null, $retryAfterSeconds);
    }

    public static function failed(?Integration $integration, ?string $failureKind, string $errorMessage, bool $flagsRefresh = false): self
    {
        return new self(ChannelOutcomeKind::Failed, $integration, $failureKind, [], $errorMessage, null, $flagsRefresh);
    }
}
