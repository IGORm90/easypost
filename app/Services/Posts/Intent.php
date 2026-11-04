<?php

namespace App\Services\Posts;

/**
 * An intent for PostGroupLifecycle::edit(). Built through a named
 * constructor so a call site reads as what it wants.
 */
final readonly class Intent
{
    private function __construct(public IntentKind $kind) {}

    /** ERROR -> QUEUE, due now. */
    public static function retry(): self
    {
        return new self(IntentKind::Retry);
    }
}
