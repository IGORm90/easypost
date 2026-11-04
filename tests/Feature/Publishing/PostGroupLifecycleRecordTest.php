<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\ChannelOutcome;
use App\Services\Posts\PostGroupLifecycle;
use App\Social\Dto\PostResultDto;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;

/*
 * recordChannel(): the half of a channel's publish that touches the database
 * (docs/plans/architecture-deepening.md, step 1.6b). It locks the segments still
 * pending, re-checks that the group is still ours to publish, hands the locked
 * segments to the attempt (a closure here, ChannelAttempt in production), and
 * records the ChannelOutcome it returns — all in one transaction. The tests give
 * it a hand-built outcome, so no provider is involved: this is the double-publish
 * protection on its own.
 *
 * Runs with no tenant, like the publish job does.
 */

/**
 * The spy standing in for the 'jobs' log channel; pass a new one to replace it.
 */
function jobsLog(?LegacyMockInterface $replace = null): LegacyMockInterface
{
    static $spy = null;

    if ($replace !== null) {
        $spy = $replace;
    }

    assert($spy !== null);

    return $spy;
}

beforeEach(function () {
    OrganizationContext::clear();

    // The attempt is logged on the 'jobs' channel after the transaction commits.
    Log::shouldReceive('channel')->with('jobs')->andReturn(jobsLog(Mockery::spy()));
    Log::shouldReceive('info', 'warning', 'error')->byDefault();
});

/**
 * @param  list<string>  $contents
 * @return array{0: PostGroup, 1: Integration, 2: EloquentCollection<int, Post>}
 */
function recordSetup(array $contents = ['a', 'b']): array
{
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    $group->update(['queue_version' => 3]);
    [$integration, $posts] = publishableChannel($org, $group, $contents);

    return [$group, $integration, new EloquentCollection($posts)];
}

it('records a published outcome: every segment PUBLISHED with its release, no retry', function () {
    [$group, $integration, $pending] = recordSetup();

    $result = app(PostGroupLifecycle::class)->recordChannel(
        $group->id,
        3,
        $pending,
        fn () => ChannelOutcome::published($integration, [
            0 => new PostResultDto('r-0', 'https://example.test/0'),
            1 => new PostResultDto('r-1'),
        ]),
    );

    expect($result)->toBe([false, null])
        ->and($pending[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($pending[0]->fresh()->release_id)->toBe('r-0')
        ->and($pending[0]->fresh()->release_url)->toBe('https://example.test/0')
        ->and($pending[1]->fresh()->release_id)->toBe('r-1');

    jobsLog()->shouldHaveReceived('info', ['publish.attempt', Mockery::on(
        fn (array $context): bool => $context['provider'] === 'fake'
            && $context['integration_id'] === $integration->id
            && $context['post_group_id'] === $group->id
            && $context['result'] === 'published'
            && $context['failure_kind'] === null
            && is_int($context['lateness_ms']),
    )]);
});

it('records a partial retry: only the segments that went out, and asks to be retried with the wait', function () {
    [$group, $integration, $pending] = recordSetup();

    $result = app(PostGroupLifecycle::class)->recordChannel(
        $group->id,
        3,
        $pending,
        fn () => ChannelOutcome::retry($integration, 'rate_limit', [0 => new PostResultDto('r-0')], 45),
    );

    expect($result)->toBe([true, 45])
        ->and($pending[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($pending[1]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($pending[1]->fresh()->error)->toBeNull();
});

it('records a failure: the message goes on every pending segment, no retry', function () {
    [$group, $integration, $pending] = recordSetup();

    $result = app(PostGroupLifecycle::class)->recordChannel(
        $group->id,
        3,
        $pending,
        fn () => ChannelOutcome::failed($integration, 'permanent', 'the post is too long'),
    );

    expect($result)->toBe([false, null])
        ->and($pending[0]->fresh()->state)->toBe(PostState::ERROR)
        ->and($pending[0]->fresh()->error)->toBe('the post is too long')
        ->and($pending[1]->fresh()->state)->toBe(PostState::ERROR)
        ->and($integration->fresh()->refresh_needed)->toBeFalse();
});

it('flags the channel for re-connection when the outcome asks for it', function () {
    [$group, $integration, $pending] = recordSetup();

    app(PostGroupLifecycle::class)->recordChannel(
        $group->id,
        3,
        $pending,
        fn () => ChannelOutcome::failed($integration, 'auth', 'token expired', flagsRefresh: true),
    );

    expect($integration->fresh()->refresh_needed)->toBeTrue()
        ->and($pending[0]->fresh()->error)->toBe('token expired');
});

it('records a failure for a channel that no longer exists, with nothing to flag or log', function () {
    [$group, , $pending] = recordSetup();

    $result = app(PostGroupLifecycle::class)->recordChannel(
        $group->id,
        3,
        $pending,
        fn () => ChannelOutcome::failed(null, null, 'The connected channel no longer exists.'),
    );

    expect($result)->toBe([false, null])
        ->and($pending[0]->fresh()->error)->toBe('The connected channel no longer exists.');

    jobsLog()->shouldNotHaveReceived('info');
});

it('hands the attempt only the segments it has locked, in chain order', function () {
    [$group, $integration, $pending] = recordSetup(['gone', 'still pending']);
    $pending[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'already-out']);
    $received = null;

    app(PostGroupLifecycle::class)->recordChannel(
        $group->id,
        3,
        $pending,
        function (EloquentCollection $locked) use (&$received, $integration): ChannelOutcome {
            $received = $locked->pluck('id')->all();

            return ChannelOutcome::retry($integration, 'transient');
        },
    );

    expect($received)->toBe([$pending[1]->id]);
});

it('never hands the attempt a segment that already carries a release, even if it still says QUEUE', function () {
    // The release_id is the anti-duplication marker: whatever the state says, a
    // segment that has one has reached the network and must not be sent again.
    [$group, $integration, $pending] = recordSetup(['has a release', 'still pending']);
    $pending[0]->update(['release_id' => 'already-out']);
    $received = null;

    app(PostGroupLifecycle::class)->recordChannel($group->id, 3, $pending, function (EloquentCollection $locked) use (&$received, $integration): ChannelOutcome {
        $received = $locked->pluck('id')->all();

        return ChannelOutcome::retry($integration, 'transient');
    });

    expect($received)->toBe([$pending[1]->id]);
});

it('never hands the attempt a segment that has since left QUEUE, such as one a concurrent abandon marked ERROR', function () {
    [$group, $integration, $pending] = recordSetup(['abandoned meanwhile', 'still pending']);
    $pending[0]->update(['state' => PostState::ERROR, 'error' => 'retries exhausted']);
    $received = null;

    app(PostGroupLifecycle::class)->recordChannel($group->id, 3, $pending, function (EloquentCollection $locked) use (&$received, $integration): ChannelOutcome {
        $received = $locked->pluck('id')->all();

        return ChannelOutcome::retry($integration, 'transient');
    });

    expect($received)->toBe([$pending[1]->id]);
});

it('never calls the attempt when there is nothing left to publish', function () {
    [$group, , $pending] = recordSetup();
    foreach ($pending as $post) {
        $post->update(['state' => PostState::PUBLISHED, 'release_id' => 'out-'.$post->order]);
    }
    $called = false;

    $result = app(PostGroupLifecycle::class)->recordChannel($group->id, 3, $pending, function () use (&$called): ChannelOutcome {
        $called = true;
        throw new LogicException('must not be called');
    });

    expect($result)->toBe([false, null])->and($called)->toBeFalse();
});

it('never calls the attempt when the group is no longer ours to publish', function (string $case) {
    [$group, , $pending] = recordSetup();
    $version = 3;

    if ($case === 'scheduled') {
        $group->update(['state' => PostState::SCHEDULED]);
    } elseif ($case === 'stale version') {
        $group->update(['queue_version' => 4]);
    } else {
        $group->update(['publish_date' => now()->addHours(8)]);
    }
    $called = false;

    $result = app(PostGroupLifecycle::class)->recordChannel($group->id, $version, $pending, function () use (&$called): ChannelOutcome {
        $called = true;
        throw new LogicException('must not be called');
    });

    expect($result)->toBe([false, null])
        ->and($called)->toBeFalse()
        ->and($pending[0]->fresh()->state)->toBe(PostState::QUEUE);
})->with(['scheduled', 'stale version', 'not due yet']);

it('leaves every segment untouched when the attempt throws', function () {
    [$group, , $pending] = recordSetup();

    expect(fn () => app(PostGroupLifecycle::class)->recordChannel(
        $group->id,
        3,
        $pending,
        fn () => throw new RuntimeException('worker killed'),
    ))->toThrow(RuntimeException::class);

    expect($pending[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($pending[1]->fresh()->state)->toBe(PostState::QUEUE);
});

// --- isPublishable(): the lock-free pre-check -----------------------------------

it('says a QUEUE group at the dispatched version and due now is publishable', function () {
    [$group] = recordSetup();

    expect(app(PostGroupLifecycle::class)->isPublishable($group->id, 3))->toBeTrue()
        ->and(app(PostGroupLifecycle::class)->isPublishable($group->id, null))->toBeTrue();
});

it('says it is not publishable once the group has moved on', function (string $case) {
    [$group] = recordSetup();

    if ($case === 'scheduled') {
        $group->update(['state' => PostState::SCHEDULED]);
    } elseif ($case === 'stale version') {
        $group->update(['queue_version' => 4]);
    } else {
        $group->update(['publish_date' => now()->addHours(8)]);
    }

    expect(app(PostGroupLifecycle::class)->isPublishable($group->id, 3))->toBeFalse();
})->with(['scheduled', 'stale version', 'not due yet']);

it('says a group that does not exist is not publishable', function () {
    expect(app(PostGroupLifecycle::class)->isPublishable('01HZZZZZZZZZZZZZZZZZZZZZZZ', 1))->toBeFalse();
});
