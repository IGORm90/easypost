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

it('bumps queue_version whenever a QUEUE group is claimed, and un-claims (with a bump) on reschedule', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    // type=now: the only create() path that both enters QUEUE and
    // dispatches immediately — see PostGroupService::resolveTargetState()'s
    // docblock. type=schedule dispatches nothing at creation at all any
    // more, so it cannot exercise "a QUEUE group is dispatched" the way
    // this test's title requires.
    $this->postJson('/api/posts', [
        'type' => 'now',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();
    $firstVersion = $group->queue_version;

    expect($firstVersion)->toBeGreaterThan(0)
        ->and($group->state)->toBe(PostState::QUEUE);

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === $firstVersion);

    // A drag while still QUEUE (job dispatched, not yet run) un-claims
    // back to SCHEDULED — see PostGroupService::updateDate()'s docblock
    // — bumping queue_version so the already-dispatched job's captured
    // version is stale, and dispatching nothing new at all.
    $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => now()->addHours(2)->toIso8601String(),
    ])->assertOk();

    $group->refresh();
    expect($group->queue_version)->toBeGreaterThan($firstVersion)
        ->and($group->state)->toBe(PostState::SCHEDULED);

    Queue::assertPushed(PublishPostJob::class, 1);
});

it('a stale job cannot publish after a reschedule even when its own due-date check alone would let it through', function () {
    // The realistic case: a job dispatched for an OLDER schedule
    // generation fires (a redelivery, or simply because ShouldBeUnique's
    // lock briefly allowed a duplicate dispatch to slip through). Its
    // OWN publish_date/due-date check (PostGroupLifecycle::isDueNow()) only
    // rejects a date materially in the FUTURE, so once the group's new
    // date has also arrived, that check alone would happily let a stale
    // job through. queue_version is what still stops it, compared
    // atomically inside the very same lockForUpdate() transaction that
    // decides whether to act — see PostGroupLifecycle::claim().
    //
    // Durable-scheduling rewrite: this used to drive the reschedule
    // through PostGroupService::updateDate(), which USED to redispatch a
    // live job carrying the fresh version. updateDate() no longer
    // dispatches anything (a reschedule un-claims to SCHEDULED instead
    // — see that method's docblock), so it can no longer produce "a
    // fresh generation, still QUEUE, due now" the way this invariant
    // needs to be exercised. The bump-and-reclaim this test needs is
    // exactly what DueDispatchService's claim (or a `type=now` edit)
    // produces in production — reproduced directly here to isolate the
    // queue_version check itself from those mechanisms' own coverage
    // elsewhere (DispatchDuePostsTest, this file's first test).
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addMinute());
    publishableChannel($org, $group, ['channel A']);

    $staleVersion = $group->queue_version;

    $group->update(['queue_version' => $staleVersion + 1, 'publish_date' => now()->subSecond()]);

    app(PublishService::class)->publishGroup($group->fresh()->id, $staleVersion);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('the redispatched job, carrying the current queue_version, does publish once due', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addMinute());
    publishableChannel($org, $group, ['channel A']);

    $group->update(['queue_version' => $group->queue_version + 1, 'publish_date' => now()->subSecond()]);
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
    // delete() now locks its own row via an org-scoped query too — see
    // PostGroupService::lockGroup()'s docblock.
    OrganizationContext::set($org);
    $group = queuedGroup($org, now()->addMinute());
    publishableChannel($org, $group, ['channel A']);
    $groupId = $group->id;
    $version = $group->queue_version;

    app(PostGroupService::class)->delete($group);

    // The job that was queued for this group before the delete lands —
    // PostGroupLifecycle::claim() re-reads the group by id inside
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
    //
    // Final-review fix: the publishing racer used to hand-write
    // `Post::query()->whereKey()->lockForUpdate()->update([...])`, which
    // proved only that guardEditable() blocks on SOME lock on that row —
    // not on the predicate the production code actually takes, and never
    // that the lock is held ACROSS the provider call. It now drives the
    // real PublishService::publishGroup(), with the fake provider
    // sleeping inside post() to open the window, so the row lock, the
    // network call and the release_id commit are all the production ones.
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
        'queue_version' => 1,
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

    // The handshake this race turns on. Final-review round 2: the delete
    // racer used to `usleep(40_000)` and hope the publish racer had
    // locked the segment row by then. Nothing guaranteed it had — under
    // CPU load the delete landed FIRST, on an unlocked row, and
    // succeeded, so `$results[1]` came back 'ok' and the test failed
    // having never opened the window it exists to prove safe (1 failure
    // in 10 clean full-suite runs, 4 more under load). The fake provider
    // now creates this file on entry to post(), which is inside
    // publishLockedChannel()'s transaction with the rows already locked,
    // and the delete racer blocks on it appearing.
    $barrier = sys_get_temp_dir().'/pest-race-barrier-'.bin2hex(random_bytes(6));

    try {
        $racers = [
            // "publish": the real publish run. publishChannel() locks the
            // pending row, calls the provider — which sleeps 150 ms
            // inside post(), with that lock held and the transaction
            // still open — and only then commits release_id.
            function () use ($group, $barrier) {
                fakeProvider()->onPostContaining('in flight', sleepMicroseconds: 150_000, barrierPath: $barrier);

                app(PublishService::class)->publishGroup($group->id, 1);
            },
            // "delete": starts once the publish racer has DEMONSTRABLY
            // acquired its lock — see raceAwaitBarrier(). Sets
            // OrganizationContext itself — this racer calls the service
            // directly rather than through HTTP, so nothing else would.
            function () use ($group, $organization, $barrier) {
                raceAwaitBarrier($barrier);
                OrganizationContext::set(Organization::query()->findOrFail($organization->id));
                app(PostGroupService::class)->delete(PostGroup::query()->findOrFail($group->id));
            },
        ];

        $results = raceConcurrently($racers);

        expect($results[0])->toBe('ok')
            // guardEditable()'s ConflictHttpException, once its lock wait
            // resolves and it re-reads a now-published segment.
            ->and($results[1])->toBe('fail:409');

        OrganizationContext::set($organization);
        $freshPost = Post::query()->whereKey($post->id)->first();
        $freshGroup = PostGroup::query()->whereKey($group->id)->first();

        expect($freshGroup)->not->toBeNull()
            ->and($freshGroup->state)->toBe(PostState::PUBLISHED)
            ->and($freshPost)->not->toBeNull()
            // A real release_id, minted by the provider inside the same
            // transaction the delete was blocked on — not a value this
            // test wrote itself.
            ->and($freshPost->release_id)->toStartWith('fake-release-')
            ->and($freshPost->state)->toBe(PostState::PUBLISHED);
    } finally {
        @unlink($barrier);
        DB::connection('race')->table('posts')->where('post_group_id', $group->id)->delete();
        DB::connection('race')->table('post_groups')->where('id', $group->id)->delete();
        DB::connection('race')->table('integrations')->where('id', $integration->id)->delete();
        DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});

it('two genuinely concurrent update() type=now calls (a double-click "publish now") still leave the group publishing exactly once', function () {
    // Review finding 1 (CRITICAL): the bug this proves fixed. An earlier
    // revision bumped queue_version via Eloquent's increment(), called
    // AFTER the caller's own transaction had already committed — which
    // sets increment()'s OWN in-memory attribute to (whatever stale value
    // this PHP object already held) + 1, never the row's real committed
    // value. Under this exact interleaving (A and B both load
    // queue_version=N; B commits first, bumps to N+1, dispatches N+1; A
    // — still holding its stale in-memory N — commits afterwards, bumps
    // ITS stale N to N+1 too, even though the row is already at N+1,
    // landing the row at N+2 while BOTH dispatched jobs carry N+1) BOTH
    // jobs would find a version mismatch and no-op — silently, since a
    // mismatch is never a failure — leaving the group stuck at QUEUE
    // forever with nothing in failed_jobs. This was a genuine regression:
    // pre-Task-31, both jobs were live and each re-read publish_date
    // under its own lock, so whichever matched the current schedule
    // published regardless of interleaving.
    //
    // Durable-scheduling rewrite: this used to drive both racers through
    // updateDate(), which USED to dispatch a live job on every call.
    // updateDate() no longer dispatches anything at all (see that
    // method's docblock) — a double-drag now just un-claims/re-writes
    // publish_date with no race to speak of. `update()`'s `type=now`
    // path is what still both re-locks the row (`lockGroup()`), bumps
    // `queue_version` under that lock (`bumpQueueVersionIfNotDraft()`),
    // and claims + dispatches immediately (`dispatch()`) — the
    // exact shape this regression needs a double-click on "publish now"
    // to reproduce.
    //
    // A sequential Pest call can never reproduce this — each call's
    // transaction fully commits before the next starts — so this uses
    // the project's real pcntl_fork() harness (tests/Pest.php), exactly
    // like the delete-vs-publish race above. QUEUE_CONNECTION is 'sync'
    // in tests, so each racer's own update() call dispatches its
    // PublishPostJob INLINE, synchronously, the instant its own
    // transaction commits — which is exactly what lets a single racer
    // closure exercise "commit the reschedule, then attempt to publish"
    // end to end, without any separate orchestration.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Race Owner', 'email' => 'reschedule-version-race-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Race Co', 'slug' => 'race-version-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);
    $integration = raceCreate(Integration::class, [
        'organization_id' => $organization->id,
        'provider_identifier' => 'fake',
        'internal_id' => (string) Str::ulid(),
        'name' => 'race channel',
    ]);
    $group = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'state' => PostState::SCHEDULED,
        'publish_date' => now()->addHour(),
        'queue_version' => 1,
    ]);
    $post = raceCreate(Post::class, [
        'post_group_id' => $group->id,
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'double-clicked',
        'settings' => [],
        'state' => PostState::SCHEDULED,
    ]);

    try {
        $racer = function () use ($group, $organization, $integration) {
            OrganizationContext::set(Organization::query()->findOrFail($organization->id));

            // Both racers click "publish now" on the same group at once.
            app(PostGroupService::class)->update(
                PostGroup::query()->findOrFail($group->id),
                [
                    'type' => 'now',
                    'posts' => [
                        ['integration_id' => $integration->id, 'value' => [['content' => 'published now']]],
                    ],
                ],
            );
        };

        // A racer whose own inline publish succeeds first leaves the
        // group PUBLISHED — the OTHER racer's update() then hits
        // guardEditable()'s "already published" guard and legitimately
        // fails (409). Which racer wins that race is not deterministic,
        // so this asserts the outcome that must hold regardless: the
        // group is never left stuck at QUEUE.
        raceConcurrently([$racer, $racer]);

        OrganizationContext::set($organization);
        $freshGroup = PostGroup::query()->whereKey($group->id)->first();
        // Not $post->id: the winning racer's update() does a full
        // replace (guardEditable()'s "safe to fully replace" contract —
        // see PostGroupService's class docblock), deleting the original
        // fixture post and creating a fresh one in its place.
        $freshPost = Post::query()->where('post_group_id', $group->id)->first();

        expect($freshGroup)->not->toBeNull()
            // The load-bearing assertion: under the pre-fix bug both
            // racers' dispatched jobs could carry a mismatched, stale
            // version and BOTH silently no-op, leaving the group stuck
            // at QUEUE forever with nothing in failed_jobs. Whichever
            // racer legitimately wins the race (one may 409 if the
            // other's publish lands first — see above), the group must
            // never end up anywhere but PUBLISHED.
            ->and($freshGroup->state)->toBe(PostState::PUBLISHED)
            ->and($freshPost)->not->toBeNull()
            ->and($freshPost->state)->toBe(PostState::PUBLISHED)
            ->and($freshPost->release_id)->not->toBeNull()
            // At least one racer's write landed under the row lock
            // (starting at 1, so >= 2); both landing (3) is the more
            // likely interleaving but not guaranteed — see above.
            ->and($freshGroup->queue_version)->toBeGreaterThanOrEqual(2);
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
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED)
        // Review finding 3: the GROUP's own error, not just each post's,
        // must be cleared by retry() — otherwise a group that goes on to
        // republish successfully still carries its previous failure
        // message, which PostGroupResource exposes verbatim to the UI.
        ->and($group->fresh()->error)->toBeNull();

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
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($group->fresh()->error)->toBeNull();

    $sentChain = $provider->publishedChains()[0];
    expect($sentChain)->toHaveCount(1)
        ->and($sentChain[0]->content)->toBe('reply')
        ->and($sentChain[0]->replyToReleaseId)->toBe('root-release-id');
});
