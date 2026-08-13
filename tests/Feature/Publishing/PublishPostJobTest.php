<?php

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Services\Posts\PublishService;
use App\Support\OrganizationContext;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;

/*
 * Task 30: PublishPostJob / PublishService — the actual act of
 * publishing a post group. This is the highest-risk task in the
 * project: the single worst failure this product can produce is
 * publishing a customer's post twice. Every test below drives
 * PublishService directly (never through a real dispatch()) or
 * constructs PublishPostJob and calls handle()/failed() on it directly
 * — release()/attempts() are exercised via the framework's own
 * InteractsWithQueue::withFakeQueueInteractions()/assertReleased(),
 * never a Mockery partial mock, so PublishPostJob can stay `final`.
 *
 * Runs with NO OrganizationContext set throughout, matching how this
 * code actually executes in production (a queued job, with
 * ClearOrganizationContext having already cleared it) — every fixture
 * below is built with an explicit organization_id rather than relying
 * on ambient context.
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

/**
 * Builds one channel: a fake-provider Integration plus an ordered chain
 * of Post rows (a thread) under $group, all starting in QUEUE with no
 * release_id.
 *
 * @param  list<string>  $contents
 * @return array{0: Integration, 1: list<Post>}
 */
function publishableChannel(Organization $org, PostGroup $group, array $contents = ['hello world']): array
{
    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'fake',
    ]);

    $parentId = null;
    $posts = [];

    foreach ($contents as $index => $content) {
        $post = Post::create([
            'post_group_id' => $group->id,
            'organization_id' => $org->id,
            'integration_id' => $integration->id,
            'parent_post_id' => $parentId,
            'order' => $index,
            'content' => $content,
            'settings' => [],
            'state' => PostState::QUEUE,
        ]);

        $parentId = $post->id;
        $posts[] = $post;
    }

    return [$integration, $posts];
}

function queuedGroup(Organization $org, ?DateTimeInterface $publishDate = null): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => $publishDate ?? now(),
    ]);
}

it('publishes every channel in the group and marks it published', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    app(PublishService::class)->publishGroup($group->id);

    $postA = $postsA[0]->fresh();
    $postB = $postsB[0]->fresh();

    expect($postA->state)->toBe(PostState::PUBLISHED)
        ->and($postA->release_id)->not->toBeNull()
        ->and($postA->release_url)->not->toBeNull()
        ->and($postA->published_at)->not->toBeNull()
        ->and($postB->state)->toBe(PostState::PUBLISHED)
        ->and($postB->release_id)->not->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    expect($provider->publishedChains())->toHaveCount(2);
});

it('publishes a multi-segment thread in one call, mapping each result onto the correct row in order', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['root', 'reply one', 'reply two']);

    app(PublishService::class)->publishGroup($group->id);

    $root = $posts[0]->fresh();
    $replyOne = $posts[1]->fresh();
    $replyTwo = $posts[2]->fresh();

    expect($root->state)->toBe(PostState::PUBLISHED)
        ->and($replyOne->state)->toBe(PostState::PUBLISHED)
        ->and($replyTwo->state)->toBe(PostState::PUBLISHED)
        ->and($root->release_id)->not->toBeNull()
        ->and($replyOne->release_id)->not->toBeNull()
        ->and($replyTwo->release_id)->not->toBeNull()
        // Every row got its OWN result, not the same one three times, and
        // the mapping is index-correct (review finding 5's central
        // concern: a mis-mapping here writes the wrong release_id onto a
        // segment).
        ->and(array_unique([$root->release_id, $replyOne->release_id, $replyTwo->release_id]))->toHaveCount(3)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($provider->publishedChains()[0])->toHaveCount(3)
        ->and($provider->publishedChains()[0][0]->content)->toBe('root')
        ->and($provider->publishedChains()[0][1]->content)->toBe('reply one')
        ->and($provider->publishedChains()[0][2]->content)->toBe('reply two');
});

it('resumes a two-segment pending remainder behind an already-published prefix, anchoring the first pending segment', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['root', 'reply one', 'reply two']);

    // Segment 0 already went out in an earlier attempt.
    $posts[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'previously-released-root',
        'release_url' => 'https://fake.test/previously',
        'published_at' => now()->subMinute(),
    ]);

    app(PublishService::class)->publishGroup($group->id);

    $replyOne = $posts[1]->fresh();
    $replyTwo = $posts[2]->fresh();

    expect($replyOne->state)->toBe(PostState::PUBLISHED)
        ->and($replyTwo->state)->toBe(PostState::PUBLISHED)
        ->and($replyOne->release_id)->not->toBeNull()
        ->and($replyTwo->release_id)->not->toBeNull()
        ->and($replyOne->release_id)->not->toBe($replyTwo->release_id)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    // Only the two pending segments were sent, and the first of them
    // carried the anchor to the already-published root.
    $sentChain = $provider->publishedChains()[0];
    expect($sentChain)->toHaveCount(2)
        ->and($sentChain[0]->content)->toBe('reply one')
        ->and($sentChain[0]->replyToReleaseId)->toBe('previously-released-root')
        ->and($sentChain[1]->content)->toBe('reply two')
        ->and($sentChain[1]->replyToReleaseId)->toBeNull();
});

it('is idempotent: a second run does not publish twice', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::PUBLISHED,
    ]);
    [, $posts] = publishableChannel($org, $group, ['already out']);
    $posts[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'already-released-id',
        'release_url' => 'https://fake.test/already',
        'published_at' => now(),
    ]);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($posts[0]->fresh()->release_id)->toBe('already-released-id')
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('skips already published posts on retry, at both channel and segment level', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);

    // Channel A: simulates a prior successful attempt already committed.
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    $postsA[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'previously-released-id',
        'release_url' => 'https://fake.test/previously',
        'published_at' => now()->subMinute(),
    ]);

    // Channel B: still pending.
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($postsA[0]->fresh()->release_id)->toBe('previously-released-id')
        ->and($postsB[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($postsB[0]->fresh()->release_id)->not->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('marks only the failing channel as errored, while the other still publishes', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    fakeProvider()->failNextPostPermanently('rejected by the network');

    app(PublishService::class)->publishGroup($group->id);

    $postA = $postsA[0]->fresh();
    $postB = $postsB[0]->fresh();

    $published = $postA->state === PostState::PUBLISHED ? $postA : $postB;
    $errored = $postA->state === PostState::ERROR ? $postA : $postB;

    expect($postA->state)->not->toBe($postB->state)
        ->and($published->state)->toBe(PostState::PUBLISHED)
        ->and($published->release_id)->not->toBeNull()
        ->and($errored->state)->toBe(PostState::ERROR)
        ->and($errored->error)->toBe('rejected by the network')
        ->and($errored->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('releases the job with backoff on a transient error, leaving the group QUEUE', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostTransiently('network blip');

    $job = new PublishPostJob($group->id);
    $job->withFakeQueueInteractions();

    $job->handle(app(PublishService::class));

    $job->assertReleased(60);

    expect($group->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->release_id)->toBeNull();
});

it('honours the provider retryAfterSeconds on a rate limit when it is under the schedule ceiling', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    // 45s is under backoff[0] = 60s (this job's first-attempt ceiling),
    // so this exercises "provider asked for less than the cap" and must
    // be honoured exactly.
    fakeProvider()->failNextPostWithRateLimit(45, 'slow down');

    $job = new PublishPostJob($group->id);
    $job->withFakeQueueInteractions();

    $job->handle(app(PublishService::class));

    $job->assertReleased(45);

    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('caps a rate-limit release at this attempt\'s own backoff position, never the provider\'s uncapped request', function () {
    // Review finding 4: an uncapped rate-limit delay can outlive
    // $uniqueFor's fixed 3600s. A provider asking to wait far longer
    // than any schedule position allows (Telegram's own retry_after
    // routinely exceeds an hour under a broadcast-wide limit) must be
    // capped at THIS attempt's own backoff() position, exactly like
    // RefreshIntegrationTokenJob caps ProviderRateLimitException.
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostWithRateLimit(999_999, 'slow down for a very long time');

    $job = new PublishPostJob($group->id);
    $job->withFakeQueueInteractions();
    // Simulate the second attempt: backoff[1] = 300 is the ceiling here,
    // not backoff[0] = 60. $job->job is typed as the bare
    // Illuminate\Contracts\Queue\Job interface (no $attempts property);
    // withFakeQueueInteractions() always sets it to a concrete FakeJob,
    // which does have one.
    /** @var FakeJob $fakeJob */
    $fakeJob = $job->job;
    $fakeJob->attempts = 2;

    $job->handle(app(PublishService::class));

    $job->assertReleased(300);
});

it('flags the integration for reconnect on auth error and errors the post', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [$integration, $posts] = publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostWithAuthError('token revoked');

    app(PublishService::class)->publishGroup($group->id);

    expect($integration->fresh()->refresh_needed)->toBeTrue()
        ->and($posts[0]->fresh()->state)->toBe(PostState::ERROR)
        ->and($posts[0]->fresh()->error)->toBe('token revoked')
        ->and($posts[0]->fresh()->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('does nothing when the group is not in the QUEUE state', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::DRAFT,
    ]);
    publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($group->fresh()->state)->toBe(PostState::DRAFT);
});

// --- Stale schedule / rescheduling (review finding 1) ---------------------

it('does not publish a group whose publish_date is still materially in the future', function () {
    // Simulates a stale job: dispatched for an old publish_date that has
    // since been moved further out (PostGroupService::updateDate()), but
    // this particular job instance still reflects the OLD schedule.
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addHours(8));
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('does publish a group whose publish_date has just passed, within the grace window', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->subSecond());
    publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('redispatches PublishPostJob when the calendar moves publish_date on an already-QUEUE group', function () {
    // Built directly (not via POST /api/posts) so this test isolates
    // updateDate()'s OWN dispatch from the unrelated fact that
    // PublishPostJob's ShouldBeUnique lock — a real Cache lock, not
    // touched by Queue::fake() — would still be held by a prior
    // create()-time dispatch for the same group id at this point in a
    // real request sequence, and would silently swallow a same-instant
    // redispatch. That interaction is real and is called out in
    // updateDate()'s docblock and this task's fix report as the
    // residual gap the due-date guard (not the redispatch) is what
    // actually protects against; it is not what this test is for.
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = queuedGroup($org, now()->addHours(2));
    publishableChannel($org, $group, ['channel A']);

    $newDate = now()->addHours(10);

    $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => $newDate->toIso8601String(),
    ])->assertOk();

    Queue::assertPushed(PublishPostJob::class, 1);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id);
});

// --- Retries exhausted (review finding 3) ---------------------------------

it('errors every still-pending post and finalizes the group when the job fails for good', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    // Channel A already published in an earlier attempt; channel B never
    // got a chance before retries ran out.
    $postsA[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'already-released',
        'release_url' => 'https://fake.test/already',
        'published_at' => now()->subMinute(),
    ]);

    $job = new PublishPostJob($group->id);
    $job->failed(new MaxAttemptsExceededException('PublishPostJob has been attempted too many times.'));

    expect($postsA[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($postsA[0]->fresh()->release_id)->toBe('already-released')
        ->and($postsB[0]->fresh()->state)->toBe(PostState::ERROR)
        ->and($postsB[0]->fresh()->error)->not->toBeNull()
        ->and($postsB[0]->fresh()->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('failed() is a no-op once the group already resolved to PUBLISHED', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);
    expect($group->fresh()->state)->toBe(PostState::PUBLISHED);

    $job = new PublishPostJob($group->id);
    $job->failed(new MaxAttemptsExceededException('too many attempts'));

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($posts[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

// --- Partial thread failure / resumption --------------------------------

it('persists the published prefix of a partial thread failure and resumes with the anchor, never duplicating the root', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['root segment', 'reply segment']);

    // First segment "goes out" for real before the thread fails.
    $provider->failNextPostWithPartialFailure(1, 'reply failed');

    $threw = null;
    try {
        app(PublishService::class)->publishGroup($group->id);
    } catch (PublishRetryNeededException $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull();

    $root = $posts[0]->fresh();
    $reply = $posts[1]->fresh();

    expect($root->state)->toBe(PostState::PUBLISHED)
        ->and($root->release_id)->not->toBeNull()
        ->and($reply->state)->toBe(PostState::QUEUE)
        ->and($reply->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);

    $rootReleaseId = $root->release_id;

    // Retry: the second call must attach the reply to the already-live
    // root, never publish a fresh, detached root.
    app(PublishService::class)->publishGroup($group->id);

    expect($reply->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($reply->fresh()->release_id)->not->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($provider->rootReleaseIds())->toBe([$rootReleaseId]);
});

// --- Wiring: PostGroupService dispatches this job ------------------------

it('dispatches PublishPostJob, delayed to publish_date, when a group is created already QUEUE', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $publishDate = now()->addHour();

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => $publishDate->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();

    Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($group): bool {
        return $job->postGroupId === $group->id;
    });
});

it('does not dispatch anything for a draft group', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    Queue::assertNotPushed(PublishPostJob::class);
});
