<?php

use App\Enums\OrganizationRole;
use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\PostGroupService;
use App\Services\Posts\PublishService;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Task 31: rescheduling, cancelling, and manually retrying a publish.
 *
 * Reuses PublishPostJobTest's `fakeProvider()`, `queuedGroup()`, and
 * `publishableChannel()` helpers (Pest loads every test file up front, so
 * their top-level function declarations are already available here — see
 * that file for the fixtures themselves).
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

// --- Reschedule cancels the stale job via queue_version --------------------

it('bumps queue_version every time a QUEUE group is (re)dispatched', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addHour()->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();
    $firstVersion = $group->queue_version;

    expect($firstVersion)->toBeGreaterThan(0);

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === $firstVersion);

    $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => now()->addHours(2)->toIso8601String(),
    ])->assertOk();

    $group->refresh();
    expect($group->queue_version)->toBeGreaterThan($firstVersion);

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === $group->queue_version);
});

it('a stale job cannot publish after a reschedule even when its own due-date check alone would let it through', function () {
    // The realistic case: a job dispatched for the OLD schedule fires
    // (a redelivery, or simply because ShouldBeUnique's lock briefly
    // allowed a duplicate dispatch to slip through — see
    // PostGroupService::dispatchPublishIfQueued()'s docblock). Its OWN
    // publish_date/due-date check (PublishService::isDueNow()) only
    // rejects a date materially in the FUTURE, so once the group's new
    // date has also arrived, that check alone would happily let a stale
    // job through. queue_version is what still stops it, compared
    // atomically inside the very same lockForUpdate() transaction that
    // decides whether to act — see PublishService::lockGroupIfQueued().
    //
    // Queue::fake() is required here for a reason distinct from the
    // usual CRUD-test footgun: without it, updateDate()'s own real
    // dispatch would run PublishPostJob SYNCHRONOUSLY (QUEUE_CONNECTION
    // is 'sync' in tests) with the CURRENT (non-stale) queue_version,
    // publishing the group for real before this test ever gets to
    // exercise the STALE call it actually wants to prove is a no-op.
    Queue::fake();

    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addMinute());
    publishableChannel($org, $group, ['channel A']);

    $staleVersion = $group->queue_version;

    app(PostGroupService::class)->updateDate($group, now()->subSecond());

    app(PublishService::class)->publishGroup($group->fresh()->id, $staleVersion);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('the redispatched job, carrying the current queue_version, does publish once due', function () {
    Queue::fake();

    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addMinute());
    publishableChannel($org, $group, ['channel A']);

    app(PostGroupService::class)->updateDate($group, now()->subSecond());
    $group->refresh();

    app(PublishService::class)->publishGroup($group->id, $group->queue_version);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('a job carrying no queue_version at all (direct PublishService call) is unaffected by the check', function () {
    // publishGroup()'s $queueVersion parameter is nullable so every
    // existing PublishPostJobTest call that drives PublishService
    // directly (with no opinion on versioning) keeps working unchanged.
    // Only PublishPostJob itself always supplies a real version in
    // production.
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1);
});

// --- Deleting a group cancels its job ---------------------------------------

it('a job for a group deleted before it fires finds nothing and does nothing', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addMinute());
    publishableChannel($org, $group, ['channel A']);
    $groupId = $group->id;
    $version = $group->queue_version;

    app(PostGroupService::class)->delete($group);

    // The job that was queued for this group before the delete lands —
    // PublishService::lockGroupIfQueued() re-reads the group by id inside
    // its own lockForUpdate() transaction and finds nothing, so this is a
    // safe no-op regardless of whether the underlying queue driver ever
    // offers a way to truly cancel an already-pushed delayed job.
    app(PublishService::class)->publishGroup($groupId, $version);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and(PostGroup::query()->find($groupId))->toBeNull();
});

it('refuses to delete a group while a publish for it is genuinely in flight, never discarding the not-yet-committed release', function () {
    // Real, OS-level concurrency (see tests/Pest.php's raceConcurrently())
    // — a sequential Pest call can never reproduce the exact window this
    // proves safe: DELETE landing after a publishChannel() transaction has
    // already locked a segment's row but before it has committed
    // release_id. PostGroupService::guardEditable() must block on that
    // same row (lockForUpdate(), same predicate publishChannel() uses)
    // until the publish either commits or rolls back, then re-read fresh
    // — never trust a snapshot taken before that resolves.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Race Owner', 'email' => 'reschedule-race-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Race Co', 'slug' => 'race-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);
    $integration = raceCreate(Integration::class, [
        'organization_id' => $organization->id,
        'provider_identifier' => 'fake',
        'internal_id' => (string) Str::ulid(),
        'name' => 'race channel',
    ]);
    $group = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'state' => PostState::QUEUE,
        'publish_date' => now(),
    ]);
    $post = raceCreate(Post::class, [
        'post_group_id' => $group->id,
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'in flight',
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);

    try {
        $racers = [
            // "publish": locks the pending row exactly like
            // PublishService::publishChannel()'s re-lock, holds it open
            // briefly to widen the window, then commits release_id.
            function () use ($post, $organization) {
                OrganizationContext::set(Organization::query()->findOrFail($organization->id));
                DB::transaction(function () use ($post) {
                    Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
                    usleep(150_000);
                    Post::query()->whereKey($post->id)->update([
                        'release_id' => 'in-flight-release',
                        'release_url' => 'https://fake.test/in-flight',
                        'published_at' => now(),
                        'state' => PostState::PUBLISHED->value,
                    ]);
                });
            },
            // "delete": starts shortly after the publish racer has
            // acquired its lock but before it commits. Sets
            // OrganizationContext itself — this racer calls the service
            // directly rather than through HTTP, so nothing else would.
            function () use ($group, $organization) {
                usleep(40_000);
                OrganizationContext::set(Organization::query()->findOrFail($organization->id));
                app(PostGroupService::class)->delete(PostGroup::query()->findOrFail($group->id));
            },
        ];

        $results = raceConcurrently($racers);

        expect($results[0])->toBe('ok')
            ->and($results[1])->toStartWith('fail:');

        OrganizationContext::set($organization);
        $freshPost = Post::query()->whereKey($post->id)->first();
        $freshGroup = PostGroup::query()->whereKey($group->id)->first();

        expect($freshGroup)->not->toBeNull()
            ->and($freshPost)->not->toBeNull()
            ->and($freshPost->release_id)->toBe('in-flight-release')
            ->and($freshPost->state)->toBe(PostState::PUBLISHED);
    } finally {
        DB::connection('race')->table('posts')->where('post_group_id', $group->id)->delete();
        DB::connection('race')->table('post_groups')->where('id', $group->id)->delete();
        DB::connection('race')->table('integrations')->where('id', $integration->id)->delete();
        DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});

// --- Manual retry ------------------------------------------------------------

it('retries a group from ERROR: moves it back to QUEUE and dispatches an immediate job', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::ERROR,
        'error' => 'the channel rejected the post',
    ]);
    $post = Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'failed segment',
        'settings' => [],
        'state' => PostState::ERROR,
        'error' => 'auth token expired',
    ]);

    $response = $this->postJson("/api/posts/{$group->id}/retry");

    $response->assertOk();
    $response->assertJsonPath('state', PostState::QUEUE->value);

    expect($group->fresh()->state)->toBe(PostState::QUEUE)
        ->and($post->fresh()->state)->toBe(PostState::QUEUE);

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id);
});

it('refuses to retry a group that is already PUBLISHED', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::PUBLISHED,
    ]);
    Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'already out',
        'settings' => [],
        'state' => PostState::PUBLISHED,
        'release_id' => 'already-released',
        'release_url' => 'https://fake.test/already',
        'published_at' => now(),
    ]);

    $response = $this->postJson("/api/posts/{$group->id}/retry");

    $response->assertStatus(422);
    expect($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('refuses to retry a group that is still QUEUE (nothing has failed yet)', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->addHour(),
    ]);

    $response = $this->postJson("/api/posts/{$group->id}/retry");

    $response->assertStatus(422);
    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

it("404s retrying another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignGroup = PostGroup::factory()->create([
        'organization_id' => $other->id,
        'state' => PostState::ERROR,
    ]);

    $this->postJson("/api/posts/{$foreignGroup->id}/retry")->assertNotFound();
});

it('retrying a partially published group re-sends only the failed channel, leaving the already-published one completely untouched', function () {
    // The realistic case the brief calls out by name: two channels
    // succeeded, one auth-failed, the group settled to ERROR. Retry must
    // touch only the failed channel's ERROR rows — the published channel's
    // release_id/release_url/published_at/state must be bit-for-bit
    // identical before and after, and the provider must never be called
    // for it again.
    //
    // Queue::fake(): retry() dispatches its own PublishPostJob, which
    // would otherwise run synchronously (sync queue driver) the instant
    // retry() is called, before this test gets to drive publishGroup()
    // explicitly and inspect the result precisely.
    Queue::fake();

    $provider = fakeProvider();

    $org = Organization::factory()->create();
    // PostGroupService::retry() runs org-scoped queries (it is normally
    // called from an HTTP request, where the 'organization' middleware
    // has already set this) — calling it directly, as this test does to
    // drive publishGroup() separately afterwards, needs the same context
    // set by hand.
    OrganizationContext::set($org);
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::ERROR,
        'error' => 'one channel failed to authenticate',
    ]);

    [, $postsPublished] = publishableChannel($org, $group, ['already out']);
    $postsPublished[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'previously-released-id',
        'release_url' => 'https://fake.test/previously',
        'published_at' => now()->subMinute(),
    ]);

    [, $postsFailed] = publishableChannel($org, $group, ['needs a retry']);
    $postsFailed[0]->update([
        'state' => PostState::ERROR,
        'error' => 'token revoked',
    ]);

    $snapshot = $postsPublished[0]->fresh();
    $snapshotReleaseId = $snapshot->release_id;
    $snapshotReleaseUrl = $snapshot->release_url;
    $snapshotPublishedAt = $snapshot->published_at;

    app(PostGroupService::class)->retry($group->fresh());
    app(PublishService::class)->publishGroup($group->fresh()->id, $group->fresh()->queue_version);

    $publishedAfter = $postsPublished[0]->fresh();
    $failedAfter = $postsFailed[0]->fresh();

    expect($publishedAfter->release_id)->toBe($snapshotReleaseId)
        ->and($publishedAfter->release_url)->toBe($snapshotReleaseUrl)
        ->and($publishedAfter->published_at?->timestamp)->toBe($snapshotPublishedAt?->timestamp)
        ->and($publishedAfter->state)->toBe(PostState::PUBLISHED)
        ->and($failedAfter->state)->toBe(PostState::PUBLISHED)
        ->and($failedAfter->release_id)->not->toBeNull()
        ->and($failedAfter->error)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    // The provider only ever saw ONE chain across both the original
    // attempt (never happened in this test — both channels start
    // pre-settled) and the retry: exactly the still-failed channel.
    expect($provider->publishedChains())->toHaveCount(1)
        ->and($provider->publishedChains()[0])->toHaveCount(1)
        ->and($provider->publishedChains()[0][0]->content)->toBe('needs a retry');
});

it('retry leaves an untouched sibling thread segment anchored to the already-published prefix', function () {
    // A single channel whose thread partially published (root out, reply
    // failed) — a narrower case of the same rule, at segment level within
    // ONE channel rather than across two. Retry must resume the reply
    // anchored to the root's release_id, never publish a fresh, detached
    // root a second time.
    Queue::fake();

    $provider = fakeProvider();

    $org = Organization::factory()->create();
    // See the identical note in the previous test — retry() runs
    // org-scoped queries and this test drives it directly, outside HTTP.
    OrganizationContext::set($org);
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::ERROR,
        'error' => 'reply failed',
    ]);
    [, $posts] = publishableChannel($org, $group, ['root', 'reply']);
    $posts[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'root-release-id',
        'release_url' => 'https://fake.test/root',
        'published_at' => now()->subMinute(),
    ]);
    $posts[1]->update(['state' => PostState::ERROR, 'error' => 'reply failed']);

    app(PostGroupService::class)->retry($group->fresh());
    app(PublishService::class)->publishGroup($group->fresh()->id, $group->fresh()->queue_version);

    $reply = $posts[1]->fresh();

    expect($reply->state)->toBe(PostState::PUBLISHED)
        ->and($reply->release_id)->not->toBeNull()
        ->and($posts[0]->fresh()->release_id)->toBe('root-release-id')
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    $sentChain = $provider->publishedChains()[0];
    expect($sentChain)->toHaveCount(1)
        ->and($sentChain[0]->content)->toBe('reply')
        ->and($sentChain[0]->replyToReleaseId)->toBe('root-release-id');
});
