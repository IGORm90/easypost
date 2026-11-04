<?php

namespace App\Services\Posts;

use Carbon\CarbonInterface;
use Closure;

/**
 * An intent for PostGroupLifecycle::edit(). Built through a named
 * constructor so a call site reads as what it wants.
 */
final readonly class Intent
{
    private function __construct(
        public IntentKind $kind,
        public ?CarbonInterface $date = null,
        /** @var array<string, mixed> */
        public array $recurrence = [],
        public ?Closure $check = null,
    ) {}

    /** ERROR -> QUEUE, due now. */
    public static function retry(): self
    {
        return new self(IntentKind::Retry);
    }

    /** Move the date of a group that has not published; a claimed (QUEUE) group is un-claimed. */
    public static function reschedule(CarbonInterface $date): self
    {
        return new self(IntentKind::Reschedule, $date);
    }

    /**
     * A full-replace save from the editor (`type` = draft | schedule | now).
     * `$date` is the already-resolved publish date (jitter applied) and
     * `$recurrence` the three `repeat_*` attributes (all null clears the rule).
     * Any `type` that is not draft or now means schedule, as before.
     *
     * `$check` is the caller's request-level check (integration ownership). It
     * runs under the group lock, right after the editability guard and before
     * the target state is decided, so the order in which a doubly-wrong request
     * is refused (409, then the check's own error, then 422) is unchanged.
     *
     * @param  array<string, mixed>  $recurrence
     */
    public static function forType(string $type, ?CarbonInterface $date, array $recurrence = [], ?Closure $check = null): self
    {
        $kind = match ($type) {
            'draft' => IntentKind::Draft,
            'now' => IntentKind::Now,
            default => IntentKind::Schedule,
        };

        return new self($kind, $date, $recurrence, $check);
    }
}
