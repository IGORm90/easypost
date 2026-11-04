<?php

namespace App\Services\Posts;

use Carbon\CarbonInterface;

/**
 * An intent for PostGroupLifecycle::edit(). Built through a named
 * constructor so a call site reads as what it wants.
 */
final readonly class Intent
{
    private function __construct(
        public IntentKind $kind,
        public ?CarbonInterface $date = null,
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
}
