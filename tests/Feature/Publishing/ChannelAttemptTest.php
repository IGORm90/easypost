<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Services\Media\Exceptions\MediaTemporaryFileException;
use App\Services\Posts\ChannelAttempt;
use App\Services\Posts\ChannelOutcome;
use App\Services\Posts\ChannelOutcomeKind;
use App\Services\Posts\PreparedChannel;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\FakeProvider;

/*
 * ChannelAttempt: one channel's trip to the network, and what came of it
 * (docs/plans/architecture-deepening.md, step 1.6). It resolves the channel,
 * applies this installation's own throttle, builds the chain, calls the provider
 * and CLASSIFIES the result into a ChannelOutcome value. It writes nothing: it is
 * the lifecycle that records an outcome. So these tests need a database only to
 * read the channel, and each one also checks that no row changed.
 *
 * Runs with no tenant, like the publish job does.
 */

beforeEach(function () {
    OrganizationContext::clear();
    fakeProvider();
});

/**
 * @param  list<string>  $contents
 * @return array{0: Integration, 1: EloquentCollection<int, Post>}
 */
function attemptChannel(array $contents = ['hello', 'world']): array
{
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [$integration, $posts] = publishableChannel($org, $group, $contents);

    return [$integration, new EloquentCollection($posts)];
}

/** The fake as it is NOW: fakeProvider() resets it, which would wipe what an assertion wants to read. */
function recordedFake(): FakeProvider
{
    /** @var FakeProvider $provider */
    $provider = app(ProviderRegistry::class)->get('fake');

    return $provider;
}

/**
 * @param  EloquentCollection<int, Post>  $pending
 */
function attemptSend(Integration $integration, EloquentCollection $pending, ?PreparedChannel $prepared = null): ChannelOutcome
{
    return app(ChannelAttempt::class)->send(
        $integration->id,
        $pending->first()->post_group_id,
        $pending,
        $prepared ?? new PreparedChannel,
    );
}

it('publishes the chain and reports every segment, writing nothing itself', function () {
    [$integration, $pending] = attemptChannel();

    $outcome = attemptSend($integration, $pending);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Published)
        ->and($outcome->failureKind)->toBeNull()
        ->and($outcome->integration?->id)->toBe($integration->id)
        ->and(array_keys($outcome->published))->toBe([0, 1])
        ->and(recordedFake()->publishedChains())->toHaveCount(1)
        ->and($pending[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($pending[0]->fresh()->release_id)->toBeNull();
});

it('threads a resumed chain onto the last segment already out', function () {
    [$integration, $pending] = attemptChannel(['first', 'second', 'third']);
    $pending[0]->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);

    /** @var EloquentCollection<int, Post> $rest */
    $rest = $pending->slice(1)->values();

    attemptSend($integration, $rest);

    expect(recordedFake()->publishedChains()[0][0]->replyToReleaseId)->toBe('remote-1');
});

it('reports a partial failure as a retry that carries the segments that did go out', function () {
    [$integration, $pending] = attemptChannel();
    fakeProvider()->failNextPostWithPartialFailure(1);

    $outcome = attemptSend($integration, $pending);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Retry)
        ->and($outcome->failureKind)->toBe('partial')
        ->and(array_keys($outcome->published))->toBe([0]);
});

it('reports a rate limit as a retry with the network\'s wait, or none when it names none', function (int $wait, ?int $expected) {
    [$integration, $pending] = attemptChannel();
    fakeProvider()->failNextPostWithRateLimit($wait);

    $outcome = attemptSend($integration, $pending);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Retry)
        ->and($outcome->failureKind)->toBe('rate_limit')
        ->and($outcome->retryAfterSeconds)->toBe($expected);
})->with([
    'a named wait' => [30, 30],
    'no wait named' => [0, null],
]);

it('reports a transient failure as a plain retry', function () {
    [$integration, $pending] = attemptChannel();
    fakeProvider()->failNextPostTransiently();

    $outcome = attemptSend($integration, $pending);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Retry)
        ->and($outcome->failureKind)->toBe('transient')
        ->and($outcome->retryAfterSeconds)->toBeNull();
});

it('reports a permanent failure as failed, with the network\'s message', function () {
    [$integration, $pending] = attemptChannel();
    fakeProvider()->failNextPostPermanently('the post is too long');

    $outcome = attemptSend($integration, $pending);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Failed)
        ->and($outcome->failureKind)->toBe('permanent')
        ->and($outcome->errorMessage)->toBe('the post is too long')
        ->and($outcome->flagsRefresh)->toBeFalse();
});

it('reports an auth failure as failed and asks for the channel to be flagged for re-connection', function () {
    [$integration, $pending] = attemptChannel();
    fakeProvider()->failNextPostWithAuthError('token expired');

    $outcome = attemptSend($integration, $pending);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Failed)
        ->and($outcome->failureKind)->toBe('auth')
        ->and($outcome->errorMessage)->toBe('token expired')
        ->and($outcome->flagsRefresh)->toBeTrue()
        // It only asks: the attempt never writes, so the channel is not flagged here.
        ->and($integration->fresh()->refresh_needed)->toBeFalse();
});

it('fails a channel whose network is no longer installed, without contacting anything', function () {
    [$integration, $pending] = attemptChannel();
    $integration->update(['provider_identifier' => 'gone-'.Str::random(6)]);

    $outcome = attemptSend($integration, $pending);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Failed)
        ->and($outcome->failureKind)->toBe('unknown_provider')
        ->and($outcome->errorMessage)->toBe('This channel\'s network is no longer available on this installation.')
        ->and(recordedFake()->publishedChains())->toBe([]);
});

it('fails a channel that has been deleted, with no integration to report on', function () {
    [, $pending] = attemptChannel();

    $outcome = app(ChannelAttempt::class)->send((string) Str::ulid(), $pending->first()->post_group_id, $pending, new PreparedChannel);

    expect($outcome->kind)->toBe(ChannelOutcomeKind::Failed)
        ->and($outcome->integration)->toBeNull()
        ->and($outcome->failureKind)->toBeNull()
        ->and($outcome->errorMessage)->toBe('The connected channel no longer exists.');
});

it('turns a media preparation failure into the outcome without calling the network', function () {
    [$integration, $pending] = attemptChannel();

    $failed = attemptSend($integration, $pending, new PreparedChannel(failure: new MediaTemporaryFileException('file missing')));
    $transient = attemptSend($integration, $pending, new PreparedChannel(failure: new ProviderTransientException('upload timed out')));

    expect($failed->kind)->toBe(ChannelOutcomeKind::Failed)
        ->and($failed->failureKind)->toBe('media')
        ->and($failed->errorMessage)->toBe('file missing')
        ->and($transient->kind)->toBe(ChannelOutcomeKind::Retry)
        ->and($transient->failureKind)->toBe('transient')
        ->and(recordedFake()->publishedChains())->toBe([]);
});

it('applies this installation\'s own throttle before contacting the network', function () {
    config(['social.rate_limits.fake' => ['max' => 1, 'decay_seconds' => 60]]);
    RateLimiter::clear('provider-throttle:fake');
    [$integration, $pending] = attemptChannel();

    $first = attemptSend($integration, $pending);
    $second = attemptSend($integration, $pending);

    expect($first->kind)->toBe(ChannelOutcomeKind::Published)
        ->and($second->kind)->toBe(ChannelOutcomeKind::Retry)
        ->and($second->failureKind)->toBe('rate_limit')
        ->and($second->retryAfterSeconds)->toBeGreaterThan(0)
        ->and(recordedFake()->publishedChains())->toHaveCount(1);

    RateLimiter::clear('provider-throttle:fake');
});
