<?php

namespace App\Enums;

/**
 * Lifecycle of a single `Post` segment or `PostGroup`
 * (`PostStateMachine` operates on either — see its docblock).
 *
 * `PUBLISHED` is terminal by design: no transition in
 * `PostStateMachine::TRANSITIONS` ever leaves it. The publishing
 * pipeline (Task 30) relies on that — once a segment carries a
 * `release_id` and sits in `PUBLISHED`, nothing in this codebase is
 * allowed to move it anywhere else, because the single worst failure
 * this product can produce is publishing a customer's post twice.
 */
enum PostState: string
{
    case DRAFT = 'DRAFT';
    case QUEUE = 'QUEUE';
    case PUBLISHED = 'PUBLISHED';
    case ERROR = 'ERROR';

    /**
     * True for exactly the states `PostStateMachine::can()` allows no
     * outgoing transition from. Currently only `PUBLISHED` — expressed
     * here as its own explicit fact (not merely inferred from an empty
     * row in the transition table) so the "publishing is a one-way door"
     * invariant has a single, directly testable source of truth.
     */
    public function isTerminal(): bool
    {
        return $this === self::PUBLISHED;
    }
}
