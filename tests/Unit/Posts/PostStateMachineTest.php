<?php

use App\Enums\PostState;
use App\Services\Posts\PostStateMachine;

/*
 * Task 18: PostState / PostStateMachine's pure transition table
 * (`PostStateMachine::can()`) and PostState::isTerminal() — neither
 * touches the database, so this file deliberately stays a true Unit
 * test with no RefreshDatabase.
 *
 * `transition()` itself (which persists via ->save()) and every
 * database-enforced invariant live in
 * tests/Feature/Posts/PostStateMachineTest.php and
 * tests/Feature/Posts/PostSchemaTest.php instead — NOT because the
 * brief's path is wrong, but because adding `uses(RefreshDatabase::class)`
 * ad hoc to a single file under tests/Unit (which Pest.php does not wire
 * RefreshDatabase into by default, unlike tests/Feature) was empirically
 * shown to corrupt transaction rollback for every test that ran
 * afterwards in the same process: a full-suite run leaked ~72 Integration
 * rows created by this file's factories into unrelated
 * tests/Feature/Integrations/IntegrationApiTest.php assertions, which
 * count `Integration::withoutGlobalScope('organization')->count()` and
 * expect an exact 0/1. Confirmed by bisection — the same suite passes
 * cleanly with `--order-by=random` (avoids the triggering order) and
 * passes deterministically once the DB-touching tests move to
 * tests/Feature, where RefreshDatabase is the well-exercised, already
 * proven-reliable directory-level binding every other Feature test uses.
 */

// Block-bodied closures throughout, deliberately, not `fn () => expect(...)`:
// the arrow-function form makes PHPStan/Larastan report the closure's
// return type against itself (Expectation's template is non-covariant —
// see phpstan.neon's existing Pest waivers for the same class of known
// limitation), a finding no other test in this codebase's `fn () =>
// expect(...)` idiom happens to trigger because none of them are this
// terse. Statement bodies avoid the implicit return entirely.
it('allows queue to published', function () {
    expect(PostStateMachine::can(PostState::QUEUE, PostState::PUBLISHED))->toBeTrue();
});
it('allows draft to queue', function () {
    expect(PostStateMachine::can(PostState::DRAFT, PostState::QUEUE))->toBeTrue();
});
it('allows queue to error', function () {
    expect(PostStateMachine::can(PostState::QUEUE, PostState::ERROR))->toBeTrue();
});
it('allows error to queue as a retry', function () {
    expect(PostStateMachine::can(PostState::ERROR, PostState::QUEUE))->toBeTrue();
});
it('allows draft to draft', function () {
    expect(PostStateMachine::can(PostState::DRAFT, PostState::DRAFT))->toBeTrue();
});

it('forbids draft to published, skipping the queue', function () {
    expect(PostStateMachine::can(PostState::DRAFT, PostState::PUBLISHED))->toBeFalse();
});
it('forbids draft to error', function () {
    expect(PostStateMachine::can(PostState::DRAFT, PostState::ERROR))->toBeFalse();
});
it('forbids error to draft', function () {
    expect(PostStateMachine::can(PostState::ERROR, PostState::DRAFT))->toBeFalse();
});
it('forbids error to published', function () {
    expect(PostStateMachine::can(PostState::ERROR, PostState::PUBLISHED))->toBeFalse();
});
it('forbids queue to draft', function () {
    expect(PostStateMachine::can(PostState::QUEUE, PostState::DRAFT))->toBeFalse();
});

it('reports PUBLISHED as terminal via PostState::isTerminal(), and no other state', function () {
    foreach (PostState::cases() as $state) {
        expect($state->isTerminal())->toBe($state === PostState::PUBLISHED);
    }
});

it('forbids every possible transition away from PUBLISHED — the terminal-state guarantee the whole product depends on', function (PostState $to) {
    expect(PostStateMachine::can(PostState::PUBLISHED, $to))->toBeFalse();
})->with(PostState::cases());
