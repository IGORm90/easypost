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
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/*
 * Task 30: PublishPostJob / PublishService — the actual act of
 * publishing a post group. This is the highest-risk task in the
 * project: the single worst failure this product can produce is
 * publishing a customer's post twice. Every test below drives
 * PublishService directly (never through a real dispatch()) or
 * constructs PublishPostJob and calls handle() on it directly, exactly
 * like RefreshIntegrationTokenJob's own tests — nothing here relies on
 * a real queue worker.
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

function queuedGroup(Organization $org): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now(),
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
    Queue::fake();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostTransiently('network blip');

    /** @var PublishPostJob&MockInterface $job */
    $job = Mockery::mock(PublishPostJob::class, [$group->id])->makePartial();
    $job->shouldReceive('release')->once()->with(60);

    $job->handle(app(PublishService::class));

    expect($group->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->release_id)->toBeNull();
});

it('honours the provider retryAfterSeconds on a rate limit instead of the fixed backoff', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostWithRateLimit(45, 'slow down');

    /** @var PublishPostJob&MockInterface $job */
    $job = Mockery::mock(PublishPostJob::class, [$group->id])->makePartial();
    $job->shouldReceive('release')->once()->with(45);

    $job->handle(app(PublishService::class));

    expect($group->fresh()->state)->toBe(PostState::QUEUE);
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
