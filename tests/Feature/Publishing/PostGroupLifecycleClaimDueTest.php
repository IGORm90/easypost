<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Services\Posts\PostGroupLifecycle;
use App\Support\OrganizationContext;

/*
 * claimDue(): the set-based claim DueDispatchService runs every minute
 * (docs/plans/architecture-deepening.md, step 1.4(e)). ONE UPDATE ... RETURNING
 * statement claims a batch of due SCHEDULED groups and stamps the dispatch
 * bookkeeping in the same write; the group's SCHEDULED posts follow in the
 * same transaction. It runs with no tenant (it claims across every
 * organization), like the scheduler does. DispatchDuePostsTest also proves it
 * through the dispatcher; this file proves it at the interface.
 */

beforeEach(fn () => OrganizationContext::clear());

function dueGroup(Organization $org, int $offsetSeconds, PostState $state = PostState::SCHEDULED, int $version = 2): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => $state,
        'publish_date' => now()->addSeconds($offsetSeconds),
        'queue_version' => $version,
        'dispatch_attempts' => 0,
        'last_dispatched_at' => null,
    ]);
}

it('claims a due SCHEDULED group: QUEUE, bookkeeping stamped, its scheduled posts follow, and the claim is returned', function () {
    $this->freezeTime();
    $org = Organization::factory()->create();
    $group = dueGroup($org, -30);
    [, $posts] = publishableChannel($org, $group, ['a', 'b']);
    foreach ($posts as $post) {
        $post->update(['state' => PostState::SCHEDULED]);
    }

    $claims = app(PostGroupLifecycle::class)->claimDue(10, 60);

    expect($claims)->toHaveCount(1)
        ->and($claims[0]->id)->toBe($group->id)
        ->and($claims[0]->queue_version)->toBe(2);

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::QUEUE)
        ->and($fresh->dispatch_attempts)->toBe(1)
        // Stamped by Postgres now(), which freezeTime() does not control.
        ->and(abs((int) $fresh->last_dispatched_at?->timestamp - now()->timestamp))->toBeLessThanOrEqual(2)
        // The claim does not bump the version: the job is dispatched for the version read here.
        ->and($fresh->queue_version)->toBe(2)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[1]->fresh()->state)->toBe(PostState::QUEUE);
});

it('claims a group inside the lookahead window and leaves one beyond it', function () {
    $org = Organization::factory()->create();
    $soon = dueGroup($org, 30);
    $later = dueGroup($org, 600);

    $claims = app(PostGroupLifecycle::class)->claimDue(10, 60);

    expect(collect($claims)->pluck('id')->all())->toBe([$soon->id])
        ->and($later->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($later->fresh()->dispatch_attempts)->toBe(0);
});

it('claims only SCHEDULED groups', function (PostState $state) {
    $org = Organization::factory()->create();
    $group = dueGroup($org, -30, $state);

    expect(app(PostGroupLifecycle::class)->claimDue(10, 60))->toBe([]);

    expect($group->fresh()->state)->toBe($state)
        ->and($group->fresh()->dispatch_attempts)->toBe(0);
})->with([
    'draft' => PostState::DRAFT,
    'queue' => PostState::QUEUE,
    'published' => PostState::PUBLISHED,
    'error' => PostState::ERROR,
]);

it('claims the earliest groups, up to the batch size, across organizations', function () {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $third = dueGroup($a, -10);
    $first = dueGroup($b, -300);
    $second = dueGroup($a, -200);

    $claims = app(PostGroupLifecycle::class)->claimDue(2, 60);

    // Which rows are claimed follows publish_date; the order UPDATE ... RETURNING hands them back in is not promised.
    expect(collect($claims)->pluck('id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and($third->fresh()->state)->toBe(PostState::SCHEDULED);
});

it('claims a group exactly once', function () {
    $org = Organization::factory()->create();
    dueGroup($org, -30);

    expect(app(PostGroupLifecycle::class)->claimDue(10, 60))->toHaveCount(1)
        ->and(app(PostGroupLifecycle::class)->claimDue(10, 60))->toBe([]);
});

it('leaves a post that is not SCHEDULED alone when its group is claimed', function () {
    $org = Organization::factory()->create();
    $group = dueGroup($org, -30);
    [, $posts] = publishableChannel($org, $group, ['out', 'draft']);
    $posts[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);
    $posts[1]->update(['state' => PostState::SCHEDULED]);

    app(PostGroupLifecycle::class)->claimDue(10, 60);

    expect($posts[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($posts[1]->fresh()->state)->toBe(PostState::QUEUE);
});
