<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Services\Posts\PostGroupLifecycle;
use App\Support\OrganizationContext;

/*
 * redispatchOrphan(): the recovery path for a group stuck in QUEUE with no live
 * job (docs/plans/architecture-deepening.md, step 1.4(f)). One atomic
 * UPDATE ... RETURNING bumps queue_version and stamps the dispatch bookkeeping
 * and hands back the version that was ACTUALLY committed, so the job it
 * dispatches can never carry a value another transaction has since moved past.
 *
 * Two behaviours are deliberate and different from every other write path, and
 * this file pins them so nobody "tidies them up" by accident:
 *  - it does NOT clear notified_at (a recovery redispatch is not a new schedule
 *    generation chosen by the author, so a failure already emailed stays emailed);
 *  - it does NOT look at the group's state: the caller's scan (MissedPostsService)
 *    already decided the group is an orphan, and a state filter here would be a
 *    behaviour change, not a refactor.
 * Runs with no tenant, like the scheduler does.
 */

beforeEach(fn () => OrganizationContext::clear());

function orphan(PostState $state = PostState::QUEUE, int $version = 5): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'state' => $state,
        'queue_version' => $version,
        'dispatch_attempts' => 2,
        'last_dispatched_at' => now()->subHour(),
        'notified_at' => now()->subDay(),
    ]);
}

it('bumps queue_version by one, counts the attempt, stamps the dispatch time and returns the committed version', function () {
    $group = orphan();

    $newVersion = app(PostGroupLifecycle::class)->redispatchOrphan($group);

    $fresh = $group->fresh();
    expect($newVersion)->toBe(6)
        ->and($fresh->queue_version)->toBe(6)
        ->and($fresh->dispatch_attempts)->toBe(3)
        // Stamped by Postgres now(), which freezeTime() does not control.
        ->and(abs((int) $fresh->last_dispatched_at?->timestamp - now()->timestamp))->toBeLessThanOrEqual(2);
});

it('returns the version the database committed, not one computed from the in-memory group', function () {
    $group = orphan(version: 5);
    // Another writer moves the row on after this instance was loaded.
    PostGroup::withoutGlobalScope('organization')->whereKey($group->id)->update(['queue_version' => 9]);

    expect(app(PostGroupLifecycle::class)->redispatchOrphan($group))->toBe(10)
        ->and($group->fresh()->queue_version)->toBe(10);
});

it('does not clear notified_at and does not change the state', function () {
    $group = orphan();
    $notifiedAt = $group->notified_at;

    app(PostGroupLifecycle::class)->redispatchOrphan($group);

    $fresh = $group->fresh();
    expect($fresh->notified_at?->timestamp)->toBe($notifiedAt?->timestamp)
        ->and($fresh->state)->toBe(PostState::QUEUE);
});

it('does not look at the state: the caller\'s scan decides what is an orphan', function () {
    $group = orphan(PostState::SCHEDULED);

    expect(app(PostGroupLifecycle::class)->redispatchOrphan($group))->toBe(6);

    expect($group->fresh()->state)->toBe(PostState::SCHEDULED);
});
