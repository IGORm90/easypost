<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Services\Posts\Intent;
use App\Services\Posts\PostGroupLifecycle;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/*
 * The write side of PostGroupLifecycle: edit($group, Intent) for the
 * HTTP-facing paths (docs/plans/architecture-deepening.md, step 1.4(c)).
 * One Intent at a time moves in. The HTTP behaviour is also pinned by
 * RescheduleTest, QueueVersionTest and MissedPostsTest; this file proves it
 * at the interface those paths now cross.
 *
 * Unlike the pipeline side (PostGroupLifecycleTest, no tenant), these calls
 * run for a signed-in tenant: the group is looked up through the
 * 'organization' scope, so the context is set here the way the middleware
 * sets it for a request.
 */

beforeEach(fn () => OrganizationContext::set(Organization::factory()->create()));

afterEach(fn () => OrganizationContext::clear());

function currentOrg(): Organization
{
    $org = OrganizationContext::get();
    assert($org !== null);

    return $org;
}

function failedGroup(Organization $org): PostGroup
{
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::ERROR,
        'error' => 'the channel rejected the post',
        'queue_version' => 3,
        'notified_at' => now()->subHour(),
        'publish_date' => now()->subDay(),
    ]);

    return $group;
}

// --- Intent::retry() ----------------------------------------------------------

it('retries an ERROR group: back to QUEUE one generation later, error and notification cleared, due now', function () {
    $this->freezeTime();
    $group = failedGroup(currentOrg());
    [, $posts] = publishableChannel(currentOrg(), $group, ['sent', 'failed']);
    $posts[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);
    $posts[1]->update(['state' => PostState::ERROR, 'error' => 'auth token expired']);

    $receipt = app(PostGroupLifecycle::class)->edit($group, Intent::retry());

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::QUEUE)
        ->and($fresh->queue_version)->toBe(4)
        ->and($fresh->error)->toBeNull()
        ->and($fresh->notified_at)->toBeNull()
        ->and($fresh->publish_date?->timestamp)->toBe(now()->timestamp)
        // Only the failed segment is re-queued; the one already out is untouched.
        ->and($posts[1]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[1]->fresh()->error)->toBeNull()
        ->and($posts[0]->fresh()->state)->toBe(PostState::PUBLISHED);

    expect($receipt->group->id)->toBe($group->id)
        ->and($receipt->group->state)->toBe(PostState::QUEUE)
        ->and($receipt->queueVersion)->toBe(4)
        // A retry must always get a fresh job, even if an old one's lock is still held.
        ->and($receipt->forced)->toBeTrue();
});

it('never re-queues a post that already holds a release_id, even if it is marked ERROR', function () {
    // The release_id is the anti-duplication marker: a post that carries one has
    // reached the network. Re-queuing it would publish it a second time.
    $group = failedGroup(currentOrg());
    [, $posts] = publishableChannel(currentOrg(), $group, ['already out', 'never sent']);
    $posts[0]->update(['state' => PostState::ERROR, 'error' => 'marked failed after it went out', 'release_id' => 'remote-1']);
    $posts[1]->update(['state' => PostState::ERROR, 'error' => 'auth token expired']);

    app(PostGroupLifecycle::class)->edit($group, Intent::retry());

    expect($posts[0]->fresh()->state)->toBe(PostState::ERROR)
        ->and($posts[0]->fresh()->error)->toBe('marked failed after it went out')
        ->and($posts[1]->fresh()->state)->toBe(PostState::QUEUE);
});

it('refuses to retry a group that has not failed, changing nothing', function (PostState $state) {
    $group = failedGroup(currentOrg());
    $group->update(['state' => $state]);

    try {
        app(PostGroupLifecycle::class)->edit($group, Intent::retry());
        test()->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['state'][0])->toBe("Only a failed post can be retried (current state: {$state->value}).");
    }

    $fresh = $group->fresh();
    expect($fresh->state)->toBe($state)
        ->and($fresh->queue_version)->toBe(3)
        ->and($fresh->error)->toBe('the channel rejected the post');
})->with([
    'draft' => PostState::DRAFT,
    'scheduled' => PostState::SCHEDULED,
    'queue' => PostState::QUEUE,
    'published' => PostState::PUBLISHED,
]);

it('does not see another organization\'s group', function () {
    $other = failedGroup(Organization::factory()->create());

    expect(fn () => app(PostGroupLifecycle::class)->edit($other, Intent::retry()))
        ->toThrow(ModelNotFoundException::class);
});
