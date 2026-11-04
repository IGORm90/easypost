<?php

use App\Enums\PostState;
use App\Services\Posts\PostGroupLifecycle;

/*
 * PostGroupLifecycle's pure transition table (`PostGroupLifecycle::allows()`)
 * and PostState::isTerminal() — neither touches the database, so this file
 * deliberately stays a true Unit test with no RefreshDatabase.
 *
 * The transitions the lifecycle actually PERFORMS (persisting, refusing,
 * leaving the model untouched) are proven through its public interface in
 * tests/Feature/Publishing/PostGroupLifecycle*Test.php, on a real database. Do
 * not add `uses(RefreshDatabase::class)` ad hoc to a single file under
 * tests/Unit: it was empirically shown to corrupt transaction rollback for
 * every test that ran afterwards in the same process, leaking rows into
 * unrelated tests that count exact rows.
 */

// Block-bodied closures throughout, deliberately, not `fn () => expect(...)`:
// the arrow-function form makes PHPStan/Larastan report the closure's
// return type against itself (Expectation's template is non-covariant —
// see phpstan.neon's existing Pest waivers for the same class of known
// limitation), a finding no other test in this codebase's `fn () =>
// expect(...)` idiom happens to trigger because none of them are this
// terse. Statement bodies avoid the implicit return entirely.
it('allows queue to published', function () {
    expect(PostGroupLifecycle::allows(PostState::QUEUE, PostState::PUBLISHED))->toBeTrue();
});
it('allows draft to scheduled', function () {
    expect(PostGroupLifecycle::allows(PostState::DRAFT, PostState::SCHEDULED))->toBeTrue();
});
it('allows scheduled to queue (claimed by the dispatcher)', function () {
    expect(PostGroupLifecycle::allows(PostState::SCHEDULED, PostState::QUEUE))->toBeTrue();
});
it('allows scheduled to scheduled (re-saving a reschedule)', function () {
    expect(PostGroupLifecycle::allows(PostState::SCHEDULED, PostState::SCHEDULED))->toBeTrue();
});
it('allows queue to scheduled (un-claim on a forward reschedule)', function () {
    expect(PostGroupLifecycle::allows(PostState::QUEUE, PostState::SCHEDULED))->toBeTrue();
});
it('allows queue to error', function () {
    expect(PostGroupLifecycle::allows(PostState::QUEUE, PostState::ERROR))->toBeTrue();
});
it('allows error to scheduled as a retry', function () {
    expect(PostGroupLifecycle::allows(PostState::ERROR, PostState::SCHEDULED))->toBeTrue();
});
it('allows draft to draft', function () {
    expect(PostGroupLifecycle::allows(PostState::DRAFT, PostState::DRAFT))->toBeTrue();
});

it('forbids draft to queue directly, skipping scheduled', function () {
    expect(PostGroupLifecycle::allows(PostState::DRAFT, PostState::QUEUE))->toBeFalse();
});
it('forbids draft to published, skipping the queue', function () {
    expect(PostGroupLifecycle::allows(PostState::DRAFT, PostState::PUBLISHED))->toBeFalse();
});
it('forbids draft to error', function () {
    expect(PostGroupLifecycle::allows(PostState::DRAFT, PostState::ERROR))->toBeFalse();
});
it('forbids scheduled to draft', function () {
    expect(PostGroupLifecycle::allows(PostState::SCHEDULED, PostState::DRAFT))->toBeFalse();
});
it('forbids scheduled to published, skipping the queue', function () {
    expect(PostGroupLifecycle::allows(PostState::SCHEDULED, PostState::PUBLISHED))->toBeFalse();
});
it('forbids error to draft', function () {
    expect(PostGroupLifecycle::allows(PostState::ERROR, PostState::DRAFT))->toBeFalse();
});
it('forbids error to queue directly, skipping scheduled', function () {
    expect(PostGroupLifecycle::allows(PostState::ERROR, PostState::QUEUE))->toBeFalse();
});
it('forbids error to published', function () {
    expect(PostGroupLifecycle::allows(PostState::ERROR, PostState::PUBLISHED))->toBeFalse();
});
it('forbids queue to draft', function () {
    expect(PostGroupLifecycle::allows(PostState::QUEUE, PostState::DRAFT))->toBeFalse();
});

it('reports PUBLISHED as terminal via PostState::isTerminal(), and no other state', function () {
    foreach (PostState::cases() as $state) {
        expect($state->isTerminal())->toBe($state === PostState::PUBLISHED);
    }
});

it('forbids every possible transition away from PUBLISHED — the terminal-state guarantee the whole product depends on', function (PostState $to) {
    expect(PostGroupLifecycle::allows(PostState::PUBLISHED, $to))->toBeFalse();
})->with(PostState::cases());
