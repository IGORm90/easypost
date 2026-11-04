<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\Post;
use App\Services\Posts\Exceptions\InvalidStateTransition;
use App\Services\Posts\PostGroupLifecycle;
use App\Social\Dto\PostResultDto;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

// --- settle(): the group's verdict, decided under the lock -------------------

it('settles a QUEUE group to PUBLISHED when every post is PUBLISHED, and says so', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['a', 'b']);
    foreach ($posts as $post) {
        $post->update(['state' => PostState::PUBLISHED, 'release_id' => 'r']);
    }

    expect(lifecycle()->settle($group->id, null))->toBe(PostState::PUBLISHED);

    expect($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('settles a QUEUE group to ERROR when any post is not PUBLISHED', function (PostState $other) {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['a', 'b']);
    $posts[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'r']);
    $posts[1]->update(['state' => $other]);

    expect(lifecycle()->settle($group->id, null))->toBe(PostState::ERROR);

    expect($group->fresh()->state)->toBe(PostState::ERROR);
})->with([
    'error' => PostState::ERROR,
    'still queued' => PostState::QUEUE,
]);

it('leaves a group that is not QUEUE alone, and never moves a PUBLISHED group', function (PostState $state) {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    $group->update(['state' => $state]);
    publishableChannel($org, $group, ['a']);

    expect(lifecycle()->settle($group->id, null))->toBeNull();

    expect($group->fresh()->state)->toBe($state);
})->with([
    'published' => PostState::PUBLISHED,
    'error' => PostState::ERROR,
    'scheduled' => PostState::SCHEDULED,
]);

it('discards its verdict when the queue_version has moved on', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    $group->update(['queue_version' => 2]);
    publishableChannel($org, $group, ['a']);

    expect(lifecycle()->settle($group->id, 1))->toBeNull();

    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('settles nothing for a group that does not exist', function () {
    expect(lifecycle()->settle((string) Str::ulid(), null))->toBeNull();
});

// --- abandon(): retries exhausted --------------------------------------------

it('abandons a QUEUE group: pending posts get the reason and the group goes to ERROR, published posts are untouched', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['out', 'pending']);
    $posts[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);

    lifecycle()->abandon($group->id, 'retries exhausted', null);

    expect($posts[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($posts[0]->fresh()->error)->toBeNull()
        ->and($posts[1]->fresh()->state)->toBe(PostState::ERROR)
        ->and($posts[1]->fresh()->error)->toBe('retries exhausted')
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('abandons nothing when the group is not QUEUE, the version is stale, or the group is not due yet', function (string $case) {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['pending']);
    $version = null;

    if ($case === 'published') {
        $group->update(['state' => PostState::PUBLISHED]);
    } elseif ($case === 'stale version') {
        $group->update(['queue_version' => 3]);
        $version = 2;
    } else {
        $group->update(['publish_date' => now()->addHours(8)]);
    }

    lifecycle()->abandon($group->id, 'retries exhausted', $version);

    expect($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->error)->toBeNull();

    $expected = $case === 'published' ? PostState::PUBLISHED : PostState::QUEUE;
    expect($group->fresh()->state)->toBe($expected);
})->with(['published', 'stale version', 'not due yet']);

it('abandons nothing for a group that does not exist', function () {
    lifecycle()->abandon((string) Str::ulid(), 'retries exhausted', null);

    expect(Post::count())->toBe(0);
});

// --- the post-level writers --------------------------------------------------

it('marks a post published, recording where it went and when', function () {
    $this->freezeTime();
    $org = Organization::factory()->create();
    [, $posts] = publishableChannel($org, queuedGroup($org));

    lifecycle()->markPostPublished($posts[0], new PostResultDto('remote-9', 'https://example.test/p/9'));

    $post = $posts[0]->fresh();
    expect($post->state)->toBe(PostState::PUBLISHED)
        ->and($post->release_id)->toBe('remote-9')
        ->and($post->release_url)->toBe('https://example.test/p/9')
        ->and($post->published_at?->timestamp)->toBe(now()->timestamp);
});

it('marks every given post as errored with the message', function () {
    $org = Organization::factory()->create();
    [, $posts] = publishableChannel($org, queuedGroup($org), ['a', 'b']);

    lifecycle()->markPostsError(new EloquentCollection($posts), 'the channel is gone');

    foreach ($posts as $post) {
        expect($post->fresh()->state)->toBe(PostState::ERROR)
            ->and($post->fresh()->error)->toBe('the channel is gone');
    }
});

it('refuses to move a PUBLISHED post, which is terminal', function () {
    $org = Organization::factory()->create();
    [, $posts] = publishableChannel($org, queuedGroup($org));
    $posts[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);

    expect(fn () => lifecycle()->markPostsError(new EloquentCollection([$posts[0]]), 'x'))
        ->toThrow(InvalidStateTransition::class);

    expect($posts[0]->fresh()->state)->toBe(PostState::PUBLISHED);
});
