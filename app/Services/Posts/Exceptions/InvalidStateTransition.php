<?php

namespace App\Services\Posts\Exceptions;

use App\Enums\PostState;
use RuntimeException;

/**
 * Thrown by `PostStateMachine::transition()` for any `(from, to)` pair
 * not present in its transition table — most importantly, ANY attempt to
 * move a `PUBLISHED` post or post group somewhere else. Never caught and
 * silently swallowed anywhere in this codebase: a caller hitting this is
 * a programming error (Task 30's publish job, the validation service, or
 * any future caller asking for a transition that was never allowed),
 * not a condition a user action should routinely trigger.
 */
class InvalidStateTransition extends RuntimeException
{
    public static function from(PostState $from, PostState $to): self
    {
        return new self("Cannot transition from {$from->value} to {$to->value}.");
    }
}
