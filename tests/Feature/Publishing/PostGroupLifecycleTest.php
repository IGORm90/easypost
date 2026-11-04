<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Services\Posts\PostGroupLifecycle;
use App\Support\OrganizationContext;
use Illuminate\Support\Str;

/*
 * The PostGroupLifecycle interface (docs/plans/architecture-deepening.md,
 * steps 1.3 onward). The claim guard moved here from
 * PostGroupLifecycle::claim(); PublishPostJobTest and
 * PublishRaceTest keep proving it through the job. This file proves it at
 * the interface the job now crosses, including the ORDER of the checks.
 *
 * Runs with NO OrganizationContext, like the job does: the guard must not
 * depend on a tenant being set.
 */

beforeEach(fn () => OrganizationContext::clear());
afterEach(fn () => OrganizationContext::clear());

function lifecycle(): PostGroupLifecycle
{
    return app(PostGroupLifecycle::class);
}

// --- claim(): who is let through ---------------------------------------------

it('lets a QUEUE group at the matching queue_version through, and returns it', function () {
    $group = queuedGroup(Organization::factory()->create());
    $group->update(['queue_version' => 3]);

    $claimed = lifecycle()->claim($group->id, 3);

    expect($claimed)->not->toBeNull()
        ->and($claimed?->id)->toBe($group->id)
        ->and($claimed?->state)->toBe(PostState::QUEUE);
});

it('treats a null queue_version as no opinion', function () {
    $group = queuedGroup(Organization::factory()->create());
    $group->update(['queue_version' => 9]);

    expect(lifecycle()->claim($group->id, null))->not->toBeNull();
});

it('returns null for a group that does not exist', function () {
    expect(lifecycle()->claim((string) Str::ulid(), 1))->toBeNull();
});

it('returns null, changing nothing, for a group that is not QUEUE', function (PostState $state) {
    $group = queuedGroup(Organization::factory()->create());
    $group->update(['state' => $state, 'queue_version' => 2]);

    expect(lifecycle()->claim($group->id, 2))->toBeNull();

    $group->refresh();
    expect($group->state)->toBe($state)
        ->and($group->queue_version)->toBe(2);
})->with([
    'draft' => PostState::DRAFT,
    'scheduled' => PostState::SCHEDULED,
    'published' => PostState::PUBLISHED,
    'error' => PostState::ERROR,
]);

it('returns null for a stale queue_version, leaving the group claimed', function () {
    $group = queuedGroup(Organization::factory()->create());
    $group->update(['queue_version' => 4]);

    expect(lifecycle()->claim($group->id, 3))->toBeNull();

    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

// --- claim(): the due check and the un-claim ---------------------------------

it('lets a group through whose publish_date has just passed or is inside the grace window', function (int $offsetSeconds) {
    $this->freezeTime();
    $group = queuedGroup(Organization::factory()->create(), now()->addSeconds($offsetSeconds));

    expect(lifecycle()->claim($group->id, null))->not->toBeNull();
})->with([
    'a minute ago' => -60,
    'right now' => 0,
    'exactly at the 5s grace edge' => 5,
]);

it('returns null and un-claims the group and its pending posts when it is materially not due yet', function () {
    $this->freezeTime();
    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addSeconds(6));
    $group->update(['last_dispatched_at' => now()->subMinute()]);
    [, $posts] = publishableChannel($org, $group, ['pending', 'already out']);
    $posts[1]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);

    expect(lifecycle()->claim($group->id, null))->toBeNull();

    $group->refresh();
    expect($group->state)->toBe(PostState::SCHEDULED)
        ->and($group->last_dispatched_at)->toBeNull()
        ->and($posts[0]->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($posts[1]->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('checks the version before the due date, so a stale job never un-claims a group', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addHours(8));
    $group->update(['queue_version' => 5]);
    [, $posts] = publishableChannel($org, $group);

    expect(lifecycle()->claim($group->id, 4))->toBeNull();

    expect($group->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE);
});

// --- isDueNow(): the one predicate the claim and the abandon path share ------

it('says a group with no publish_date is due', function () {
    $group = queuedGroup(Organization::factory()->create());
    $group->publish_date = null;

    expect(lifecycle()->isDueNow($group))->toBeTrue();
});

it('says a group is due up to 5 seconds ahead, and not beyond', function () {
    $this->freezeTime();
    $group = queuedGroup(Organization::factory()->create());

    $group->publish_date = now()->addSeconds(5);
    expect(lifecycle()->isDueNow($group))->toBeTrue();

    $group->publish_date = now()->addSeconds(6);
    expect(lifecycle()->isDueNow($group))->toBeFalse();
});
