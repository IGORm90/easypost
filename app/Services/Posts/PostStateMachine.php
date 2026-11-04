<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\Exceptions\InvalidStateTransition;

/**
 * The single source of truth for which `PostState` transitions are
 * allowed, for both `Post` (one segment on one channel) and `PostGroup`
 * (the calendar-level entity spanning every channel a user scheduled
 * together) — both share the exact same lifecycle and the exact same
 * rules, so one table serves both rather than duplicating it per model.
 *
 * Allowed transitions: `DRAFT->SCHEDULED`, `SCHEDULED->QUEUE` (claimed
 * by `DispatchDuePostsCommand`), `QUEUE->PUBLISHED`, `QUEUE->ERROR`,
 * `QUEUE->SCHEDULED` (un-claim: `PostGroupLifecycle::claim()`
 * finds the row rescheduled forward out from under a stale claim),
 * `ERROR->SCHEDULED` (retry), `DRAFT->DRAFT` and `SCHEDULED->SCHEDULED`
 * (re-saving a draft / a reschedule). Everything else — including every
 * transition FROM `PUBLISHED` — throws `InvalidStateTransition`.
 *
 * `PUBLISHED` is deliberately terminal: it has no entry in
 * self::TRANSITIONS at all, so no future edit to this table can
 * accidentally open an exit from it without a reviewer seeing a brand
 * new array key appear. See `PostState::isTerminal()` for the same
 * fact expressed as a direct, independently testable property rather
 * than something only inferable from this table's shape. The publishing
 * pipeline (Task 30) depends on this: once a segment is `PUBLISHED` (and
 * therefore carries a `release_id` — see the `posts` migration's
 * immutability trigger), nothing in this codebase may move it anywhere
 * else, because doing so is how a customer's post gets sent twice.
 */
class PostStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        PostState::DRAFT->value => [PostState::DRAFT->value, PostState::SCHEDULED->value],
        PostState::SCHEDULED->value => [PostState::SCHEDULED->value, PostState::QUEUE->value],
        PostState::QUEUE->value => [PostState::PUBLISHED->value, PostState::ERROR->value, PostState::SCHEDULED->value],
        PostState::ERROR->value => [PostState::SCHEDULED->value],
        // PostState::PUBLISHED has no entry: no key means no allowed
        // targets, checked via ?? [] below. This is what makes PUBLISHED
        // terminal.
    ];

    /**
     * Pure predicate, no side effects — used by callers that need to
     * decide whether to even attempt a transition (e.g. to grey out a
     * UI action) without risking `transition()`'s exception.
     */
    public static function can(PostState $from, PostState $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * Moves `$model` to `$to` and persists it, or throws
     * `InvalidStateTransition` and leaves `$model` completely untouched
     * (the check happens before any mutation).
     */
    public function transition(Post|PostGroup $model, PostState $to): void
    {
        $from = $model->state;

        if (! self::can($from, $to)) {
            throw InvalidStateTransition::from($from, $to);
        }

        $model->state = $to;
        $model->save();
    }
}
