<?php

use App\Enums\PostState;
use App\Enums\RepeatUnit;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\Intent;
use App\Services\Posts\PostGroupLifecycle;
use App\Support\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

// --- Intent::reschedule() -----------------------------------------------------

function groupIn(PostState $state, int $version = 3): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => currentOrg()->id,
        'state' => $state,
        'queue_version' => $version,
        'notified_at' => now()->subHour(),
        'publish_date' => now()->addDay(),
    ]);
}

it('moves the date of a DRAFT or SCHEDULED group and nothing else: no version bump, no dispatch', function (PostState $state) {
    $this->freezeTime();
    $group = groupIn($state);
    $newDate = now()->addDays(3);

    $receipt = app(PostGroupLifecycle::class)->edit($group, Intent::reschedule($newDate));

    $fresh = $group->fresh();
    expect($fresh->publish_date?->timestamp)->toBe($newDate->timestamp)
        ->and($fresh->state)->toBe($state)
        ->and($fresh->queue_version)->toBe(3)
        ->and($fresh->notified_at)->not->toBeNull()
        ->and($receipt->group->id)->toBe($group->id)
        ->and($receipt->forced)->toBeFalse();
})->with([
    'draft' => PostState::DRAFT,
    'scheduled' => PostState::SCHEDULED,
]);

it('un-claims a QUEUE group back to SCHEDULED, bumping the version once so the job already dispatched goes stale', function () {
    $this->freezeTime();
    $group = groupIn(PostState::QUEUE);
    [, $posts] = publishableChannel(currentOrg(), $group, ['a', 'b']);
    $newDate = now()->addHours(2);

    $receipt = app(PostGroupLifecycle::class)->edit($group, Intent::reschedule($newDate));

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::SCHEDULED)
        ->and($fresh->queue_version)->toBe(4)
        ->and($fresh->notified_at)->toBeNull()
        ->and($fresh->publish_date?->timestamp)->toBe($newDate->timestamp)
        ->and($posts[0]->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($posts[1]->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($receipt->queueVersion)->toBe(4);
});

it('refuses to reschedule a group that has a segment on the network, changing nothing', function () {
    $group = groupIn(PostState::QUEUE);
    [, $posts] = publishableChannel(currentOrg(), $group, ['out', 'pending']);
    $posts[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);
    $originalDate = $group->publish_date;

    expect(fn () => app(PostGroupLifecycle::class)->edit($group, Intent::reschedule(now()->addDays(2))))
        ->toThrow(ConflictHttpException::class);

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::QUEUE)
        ->and($fresh->queue_version)->toBe(3)
        ->and($fresh->publish_date?->timestamp)->toBe($originalDate?->timestamp)
        ->and($posts[1]->fresh()->state)->toBe(PostState::QUEUE);
});

it('refuses to reschedule a PUBLISHED group', function () {
    $group = groupIn(PostState::PUBLISHED);

    expect(fn () => app(PostGroupLifecycle::class)->edit($group, Intent::reschedule(now()->addDays(2))))
        ->toThrow(ConflictHttpException::class);

    expect($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('does not see another organization\'s group when rescheduling', function () {
    $other = PostGroup::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'state' => PostState::SCHEDULED,
    ]);

    expect(fn () => app(PostGroupLifecycle::class)->edit($other, Intent::reschedule(now()->addDay())))
        ->toThrow(ModelNotFoundException::class);
});

// --- Intent::forType(): the PUT paths (draft / schedule / now) -----------------

/** @return array{repeat_unit: null, repeat_interval: null, repeat_until: null} */
function noRepeat(): array
{
    return ['repeat_unit' => null, 'repeat_interval' => null, 'repeat_until' => null];
}

it('publishes a draft now: QUEUE one generation later, a forced receipt, and the body runs after the transition', function () {
    $this->freezeTime();
    $group = groupIn(PostState::DRAFT, 0);
    $seen = [];

    $receipt = app(PostGroupLifecycle::class)->edit(
        $group,
        Intent::forType('now', now(), noRepeat()),
        function (PostGroup $locked, PostState $target) use (&$seen): void {
            $seen = [$locked->id, $locked->state, $target];
        },
    );

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::QUEUE)
        ->and($fresh->queue_version)->toBe(1)
        ->and($fresh->notified_at)->toBeNull()
        ->and($fresh->publish_date?->timestamp)->toBe(now()->timestamp)
        ->and($seen)->toBe([$group->id, PostState::QUEUE, PostState::QUEUE])
        ->and($receipt->queueVersion)->toBe(1)
        ->and($receipt->forced)->toBeTrue();
});

it('re-schedules a SCHEDULED group: stays SCHEDULED, one generation later, notification cleared, rule applied', function () {
    $this->freezeTime();
    $group = groupIn(PostState::SCHEDULED, 3);
    $date = now()->addDays(2);
    $until = now()->addMonths(2);

    app(PostGroupLifecycle::class)->edit($group, Intent::forType('schedule', $date, [
        'repeat_unit' => RepeatUnit::WEEK,
        'repeat_interval' => 2,
        'repeat_until' => $until,
    ]));

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::SCHEDULED)
        ->and($fresh->queue_version)->toBe(4)
        ->and($fresh->notified_at)->toBeNull()
        ->and($fresh->publish_date?->timestamp)->toBe($date->timestamp)
        ->and($fresh->repeat_unit)->toBe(RepeatUnit::WEEK)
        ->and($fresh->repeat_interval)->toBe(2)
        ->and($fresh->repeat_until?->timestamp)->toBe($until->timestamp);
});

it('clears the recurrence rule when the intent carries none', function () {
    $group = groupIn(PostState::SCHEDULED, 3);
    $group->update(['repeat_unit' => RepeatUnit::DAY, 'repeat_interval' => 1, 'repeat_until' => now()->addMonth()]);

    app(PostGroupLifecycle::class)->edit($group, Intent::forType('schedule', now()->addDay(), noRepeat()));

    $fresh = $group->fresh();
    expect($fresh->repeat_unit)->toBeNull()
        ->and($fresh->repeat_interval)->toBeNull()
        ->and($fresh->repeat_until)->toBeNull();
});

it('keeps a draft a draft without touching the version or the notification flag', function () {
    $group = groupIn(PostState::DRAFT, 3);

    $receipt = app(PostGroupLifecycle::class)->edit($group, Intent::forType('draft', now()->addDays(5), noRepeat()));

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::DRAFT)
        ->and($fresh->queue_version)->toBe(3)
        ->and($fresh->notified_at)->not->toBeNull()
        ->and($receipt->queueVersion)->toBe(3);
});

it('refuses to send a non-draft group back to draft, running no body and changing nothing', function () {
    $group = groupIn(PostState::SCHEDULED, 3);
    $ran = false;

    try {
        app(PostGroupLifecycle::class)->edit($group, Intent::forType('draft', null, noRepeat()), function () use (&$ran): void {
            $ran = true;
        });
        test()->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['type'][0])->toBe('Cannot move a post from SCHEDULED back to draft.');
    }

    expect($ran)->toBeFalse()
        ->and($group->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($group->fresh()->queue_version)->toBe(3);
});

it('rolls everything back when the body throws', function () {
    $group = groupIn(PostState::DRAFT, 0);

    expect(fn () => app(PostGroupLifecycle::class)->edit(
        $group,
        Intent::forType('now', now(), noRepeat()),
        fn () => throw new RuntimeException('chains failed'),
    ))->toThrow(RuntimeException::class);

    $fresh = $group->fresh();
    expect($fresh->state)->toBe(PostState::DRAFT)
        ->and($fresh->queue_version)->toBe(0);
});

it('runs the caller\'s check after the editability guard and before the state decision', function () {
    $check = fn () => throw new AuthorizationException;

    // Check before the "cannot go back to draft" rule: the caller gets the check's error.
    $scheduled = groupIn(PostState::SCHEDULED);
    expect(fn () => app(PostGroupLifecycle::class)->edit($scheduled, Intent::forType('draft', null, noRepeat(), $check)))
        ->toThrow(AuthorizationException::class);

    // Guard before the check: a published group answers 409 and the check never runs.
    $ran = false;
    $published = groupIn(PostState::PUBLISHED);
    expect(fn () => app(PostGroupLifecycle::class)->edit($published, Intent::forType('draft', null, noRepeat(), function () use (&$ran): void {
        $ran = true;
    })))->toThrow(ConflictHttpException::class);
    expect($ran)->toBeFalse();
});

it('does not see another organization\'s group when writing', function () {
    $other = PostGroup::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'state' => PostState::DRAFT,
    ]);

    expect(fn () => app(PostGroupLifecycle::class)->edit($other, Intent::forType('draft', null, noRepeat())))
        ->toThrow(ModelNotFoundException::class);
});

// --- create(): a brand-new group -----------------------------------------------

it('creates a publish-now group straight in QUEUE at version 1, unforced, with the body run in its new state', function () {
    $this->freezeTime();
    $id = (string) Str::ulid();
    $seen = [];

    $receipt = app(PostGroupLifecycle::class)->create(
        $id,
        Intent::forType('now', now(), noRepeat()),
        function (PostGroup $group, PostState $target) use (&$seen): void {
            $seen = [$group->id, $group->state, $target];
        },
    );

    $fresh = PostGroup::findOrFail($id);
    expect($fresh->state)->toBe(PostState::QUEUE)
        ->and($fresh->queue_version)->toBe(1)
        ->and($fresh->organization_id)->toBe(currentOrg()->id)
        ->and($fresh->publish_date?->timestamp)->toBe(now()->timestamp)
        ->and($seen)->toBe([$id, PostState::QUEUE, PostState::QUEUE])
        ->and($receipt->group->id)->toBe($id)
        ->and($receipt->queueVersion)->toBe(1)
        ->and($receipt->forced)->toBeFalse();
});

it('creates a scheduled group in SCHEDULED at version 0, with its date and recurrence rule', function () {
    $this->freezeTime();
    $id = (string) Str::ulid();
    $date = now()->addDays(2);
    $until = now()->addMonths(1);

    app(PostGroupLifecycle::class)->create($id, Intent::forType('schedule', $date, [
        'repeat_unit' => RepeatUnit::DAY,
        'repeat_interval' => 3,
        'repeat_until' => $until,
    ]), fn () => null);

    $fresh = PostGroup::findOrFail($id);
    expect($fresh->state)->toBe(PostState::SCHEDULED)
        ->and($fresh->queue_version)->toBe(0)
        ->and($fresh->publish_date?->timestamp)->toBe($date->timestamp)
        ->and($fresh->repeat_unit)->toBe(RepeatUnit::DAY)
        ->and($fresh->repeat_interval)->toBe(3);
});

it('creates a draft in DRAFT at version 0', function () {
    $id = (string) Str::ulid();

    app(PostGroupLifecycle::class)->create($id, Intent::forType('draft', now(), noRepeat()), fn () => null);

    $fresh = PostGroup::findOrFail($id);
    expect($fresh->state)->toBe(PostState::DRAFT)
        ->and($fresh->queue_version)->toBe(0);
});

it('keeps the id it was given, so a date jittered from that id matches the row', function () {
    $id = (string) Str::ulid();

    $receipt = app(PostGroupLifecycle::class)->create($id, Intent::forType('schedule', now()->addDay(), noRepeat()), fn () => null);

    expect($receipt->group->id)->toBe($id)
        ->and(PostGroup::query()->whereKey($id)->exists())->toBeTrue()
        ->and(PostGroup::count())->toBe(1);
});

it('leaves no group behind when the body throws', function () {
    $id = (string) Str::ulid();

    expect(fn () => app(PostGroupLifecycle::class)->create(
        $id,
        Intent::forType('now', now(), noRepeat()),
        fn () => throw new RuntimeException('chains failed'),
    ))->toThrow(RuntimeException::class);

    expect(PostGroup::query()->whereKey($id)->exists())->toBeFalse();
});

it('creates a group for a given organization, author and series parent when there is no tenant context', function () {
    $org = currentOrg();
    $author = User::factory()->create();
    $parent = groupIn(PostState::PUBLISHED);
    $id = (string) Str::ulid();
    // A queue worker has no OrganizationContext: everything the creating hook
    // would normally fill from it has to be passed in.
    OrganizationContext::clear();

    $receipt = app(PostGroupLifecycle::class)->create(
        $id,
        Intent::forType('schedule', now()->addWeek(), [
            'repeat_unit' => RepeatUnit::WEEK,
            'repeat_interval' => 1,
            'repeat_until' => now()->addMonths(2),
        ]),
        fn () => null,
        [
            'organization_id' => $org->id,
            'created_by' => $author->id,
            'repeat_parent_id' => $parent->id,
        ],
    );

    $fresh = PostGroup::withoutGlobalScope('organization')->findOrFail($id);
    expect($fresh->state)->toBe(PostState::SCHEDULED)
        ->and($fresh->organization_id)->toBe($org->id)
        ->and($fresh->created_by)->toBe($author->id)
        ->and($fresh->repeat_parent_id)->toBe($parent->id)
        ->and($fresh->repeat_unit)->toBe(RepeatUnit::WEEK)
        ->and($receipt->queueVersion)->toBe(0);
});

// --- remove() -------------------------------------------------------------------

it('removes a group that has nothing on the network, taking its posts with it', function (PostState $state) {
    $group = groupIn($state);
    publishableChannel(currentOrg(), $group, ['a', 'b']);

    app(PostGroupLifecycle::class)->remove($group);

    expect(PostGroup::query()->whereKey($group->id)->exists())->toBeFalse()
        ->and(Post::withoutGlobalScope('organization')->where('post_group_id', $group->id)->count())->toBe(0);
})->with([
    'draft' => PostState::DRAFT,
    'scheduled' => PostState::SCHEDULED,
    'queue' => PostState::QUEUE,
    'error' => PostState::ERROR,
]);

it('refuses to remove a group that has a segment on the network, leaving it whole', function () {
    $group = groupIn(PostState::ERROR);
    [, $posts] = publishableChannel(currentOrg(), $group, ['out', 'failed']);
    $posts[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);

    expect(fn () => app(PostGroupLifecycle::class)->remove($group))->toThrow(ConflictHttpException::class);

    expect(PostGroup::query()->whereKey($group->id)->exists())->toBeTrue()
        ->and(Post::withoutGlobalScope('organization')->where('post_group_id', $group->id)->count())->toBe(2);
});

it('refuses to remove a PUBLISHED group', function () {
    $group = groupIn(PostState::PUBLISHED);

    expect(fn () => app(PostGroupLifecycle::class)->remove($group))->toThrow(ConflictHttpException::class);

    expect(PostGroup::query()->whereKey($group->id)->exists())->toBeTrue();
});

it('does not see another organization\'s group when removing', function () {
    $other = PostGroup::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'state' => PostState::DRAFT,
    ]);

    expect(fn () => app(PostGroupLifecycle::class)->remove($other))->toThrow(ModelNotFoundException::class);

    expect(PostGroup::withoutGlobalScope('organization')->whereKey($other->id)->exists())->toBeTrue();
});
