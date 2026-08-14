<?php

use App\Enums\OrganizationRole;
use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Jobs\ScanMissedPostsJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\User;
use App\Notifications\PostFailedNotification;
use App\Services\Posts\MissedPostsService;
use App\Services\Posts\PostGroupService;
use App\Support\OrganizationContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Task 32: MissedPostsService / ScanMissedPostsJob — the safety net for
 * the two holes PublishService::lockGroupIfQueued()'s docblock
 * documents: a crash/outage between commit and dispatch, and a
 * ShouldBeUnique swallow outside the already-hardened reschedule paths.
 * Both leave a group at QUEUE, publish_date in the past, no live job.
 *
 * Runs with NO OrganizationContext set throughout, matching production
 * (a scheduled job — see AppServiceProvider's queue-event listeners) —
 * every fixture is built with an explicit organization_id.
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

function overdueGroup(Organization $org, ?DateTimeInterface $publishDate = null): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => $publishDate ?? now()->subMinutes(20),
    ]);
}

// --- Redispatch: orphaned vs. fresh ----------------------------------------

it('redispatches a group stuck at QUEUE well past its publish date', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    app(MissedPostsService::class)->scan();

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id
        && $job->queueVersion === $group->fresh()->queue_version);
});

it('logs a warning when it redispatches an orphaned group', function () {
    // Log::shouldReceive(), not Log::spy()+shouldHaveReceived() — see
    // ImpersonationTest's identical note: the latter's assertion method
    // only exists on the Mockery spy instance Facade::__callStatic()
    // forwards to at runtime, invisible to Larastan's static analysis.
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'missed_posts.redispatched'
            && $context['post_group_id'] === $group->id);

    app(MissedPostsService::class)->scan();
});

it('does not touch a group whose publish date is not overdue yet', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    // Within the OVERDUE_MINUTES (15) grace window.
    overdueGroup($org, now()->subMinutes(5));

    app(MissedPostsService::class)->scan();

    Queue::assertNotPushed(PublishPostJob::class);
});

it('does not touch a group that is not QUEUE at all', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::PUBLISHED,
        'publish_date' => now()->subMinutes(20),
    ]);

    app(MissedPostsService::class)->scan();

    Queue::assertNotPushed(PublishPostJob::class);
});

// --- Mid-retry groups must be left alone -----------------------------------

it('does not touch a group that is legitimately mid-retry, and logs nothing for it', function () {
    // PublishPostJob's own backoff schedule ([60, 300, 900] across 4
    // tries) can keep a group at QUEUE, past its publish_date, for
    // roughly 21 minutes while genuinely retrying — well past this
    // scanner's own 15-minute OVERDUE_MINUTES threshold. The mechanism
    // that must save it is the ShouldBeUnique lock, not the date
    // threshold: this test holds the EXACT lock a real in-flight
    // PublishPostJob dispatch would hold (same pattern as
    // TokenRefreshTest's "never even queues a second refresh job..."
    // case) and proves the scanner backs off completely — no dispatch,
    // no warning logged — rather than merely "no duplicate eventually".
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    Log::shouldReceive('warning')->never();

    $inFlight = new PublishPostJob($group->id, $group->queue_version);
    $lock = Cache::lock(UniqueLock::getKey($inFlight), 3600);
    expect($lock->get())->toBeTrue();

    try {
        app(MissedPostsService::class)->scan();

        Queue::assertNotPushed(PublishPostJob::class);
    } finally {
        $lock->release();
    }
});

// --- Leaked locks: bounded, observable recovery -----------------------------

it('treats a lock that has clearly outlived any legitimate attempt as a leak, force-releases it, logs at error, and still redispatches', function () {
    // Review finding 3: a hard-killed worker (SIGKILL mid-handle())
    // never runs CallQueuedHandler's release path at all, so the
    // ShouldBeUnique lock can sit held for up to its own $uniqueFor
    // (3600s) with NO live job behind it — the scanner must not simply
    // decline forever. The bound: PublishPostJob's lock is acquired at
    // DISPATCH time, independent of ->delay() (see
    // PostGroupService::dispatchPublishIfQueued()'s docblock), so the
    // absolute latest a lock belonging to a legitimate attempt for
    // THIS publish_date could still be alive is publish_date +
    // uniqueFor. This group's publish_date is set well past that.
    Queue::fake();

    $org = Organization::factory()->create();
    $uniqueFor = (new PublishPostJob('probe', 1))->uniqueFor;
    $group = overdueGroup($org, now()->subSeconds($uniqueFor + 120));

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'missed_posts.leaked_lock'
            && $context['post_group_id'] === $group->id);
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'missed_posts.leaked_lock_redispatched'
            && $context['post_group_id'] === $group->id);

    $leaked = new PublishPostJob($group->id, $group->queue_version);
    $lockKey = UniqueLock::getKey($leaked);
    $lock = Cache::lock($lockKey, $uniqueFor);
    expect($lock->get())->toBeTrue();
    // Deliberately never released by this test itself — the whole
    // point is that nothing ever will, unless the scanner does it.

    try {
        app(MissedPostsService::class)->scan();

        Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id
            && $job->queueVersion === $group->fresh()->queue_version);
    } finally {
        Cache::lock($lockKey)->forceRelease();
    }
});

it('does not treat a lock as leaked while it could still legitimately belong to a live attempt', function () {
    // The other side of the same bound: a lock held well within
    // publish_date + uniqueFor must NOT be force-released or logged as
    // a leak, even though it is well past this scanner's own 15-minute
    // OVERDUE_MINUTES threshold (matches "does not touch a group that
    // is legitimately mid-retry" above, but asserts the leak-specific
    // log/force-release never fire).
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    Log::shouldReceive('error')->never();
    Log::shouldReceive('warning')->never();

    $inFlight = new PublishPostJob($group->id, $group->queue_version);
    $lock = Cache::lock(UniqueLock::getKey($inFlight), 3600);
    expect($lock->get())->toBeTrue();

    try {
        app(MissedPostsService::class)->scan();

        Queue::assertNotPushed(PublishPostJob::class);
    } finally {
        $lock->release();
    }
});

// --- Never double-publishes, proven with real OS-level concurrency ---------

it('a scanner redispatch racing a genuinely in-flight publish never double-publishes', function () {
    // Review finding 4: the previous version of this test returned at
    // the lock probe before dispatch() was ever reached, so it proved
    // nothing beyond the "mid-retry" test above it. This uses the
    // project's real pcntl_fork() harness (tests/Pest.php) to force a
    // genuine interleaving between an in-flight publish (racer 1,
    // holding the exact per-row lock PublishService::publishChannel()
    // takes) and the scanner's own redispatch path (racer 2) — proving
    // the guarantee documented on MissedPostsService::redispatchIfOrphaned()
    // holds under real concurrency, not just sequentially. Racer 1
    // deliberately does NOT hold the ShouldBeUnique cache lock at all
    // (simulating exactly the "lock already gone" state a leaked or
    // naturally-expired lock leaves behind — see that method's
    // docblock on far-scheduled posts), so the scanner's probe finds it
    // free and dispatches for real: the per-row lock alone must be
    // what prevents the double publish here, not the cache lock.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Race Owner', 'email' => 'missed-posts-publish-race-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Race Co', 'slug' => 'race-missed-posts-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);
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
        'publish_date' => now()->subMinutes(20),
        'queue_version' => 1,
    ]);
    $post = raceCreate(Post::class, [
        'post_group_id' => $group->id,
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'racing redispatch',
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);

    try {
        $racers = [
            // "in-flight publish": locks the pending row exactly like
            // PublishService::publishChannel()'s re-lock, holds it open
            // briefly to widen the window, then commits release_id —
            // WITHOUT ever touching PublishPostJob's ShouldBeUnique
            // cache lock, so the scanner's probe genuinely finds
            // nothing held.
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
            // "scanner redispatch": starts shortly after the in-flight
            // racer has acquired its row lock but before it commits —
            // exactly MissedPostsService's own redispatch path. Sync
            // queue in tests means the dispatched PublishPostJob runs
            // inline, synchronously, inside this same racer call.
            function () {
                usleep(40_000);
                OrganizationContext::clear();
                app(MissedPostsService::class)->scan();
            },
        ];

        $results = raceConcurrently($racers);

        expect($results[0])->toBe('ok')
            ->and($results[1])->toBe('ok');

        $freshPost = Post::query()->withoutGlobalScope('organization')->whereKey($post->id)->first();

        // If the redispatched job had attempted to publish this
        // already-published row a second time, either the
        // posts_release_id_immutable trigger would have raised (making
        // racer 2 fail above) or release_id would have been overwritten
        // — neither happened.
        expect($freshPost->release_id)->toBe('in-flight-release')
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

// --- Cross-tenant enumeration -----------------------------------------------

it('enumerates overdue groups across every tenant with no organization context set', function () {
    Queue::fake();
    OrganizationContext::clear();
    expect(OrganizationContext::get())->toBeNull();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Created with an explicit organization_id (not through the
    // ambient/scoped ORM) precisely because no context is set here —
    // BelongsToOrganization's creating() hook would otherwise leave
    // organization_id null.
    $groupA = PostGroup::factory()->create([
        'organization_id' => $orgA->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->subMinutes(30),
    ]);
    $groupB = PostGroup::factory()->create([
        'organization_id' => $orgB->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->subMinutes(45),
    ]);

    expect(OrganizationContext::get())->toBeNull();

    app(MissedPostsService::class)->scan();

    expect(OrganizationContext::get())->toBeNull();

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $groupA->id);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $groupB->id);
});

// --- Notification: sent once, group.error made useful ----------------------

it('notifies the author and records a customer-facing error once a group settles to ERROR', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $owner = $org->owner;
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => $owner->id,
        'state' => PostState::ERROR,
        'error' => null,
    ]);
    $integration = Integration::factory()->for($org)->create(['name' => 'My Telegram Channel']);
    Post::factory()->for($group, 'group')->create([
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => PostState::ERROR,
    ]);

    app(MissedPostsService::class)->scan();

    $fresh = $group->fresh();
    expect($fresh->notified_at)->not->toBeNull()
        ->and($fresh->error)->not->toBeNull()
        ->and($fresh->error)->toContain('My Telegram Channel')
        // Never the raw internal reason a fresh, unlabeled ERROR group
        // has none of anyway — this asserts the summary is genuinely
        // built from the customer-visible channel name, not left empty
        // or generic when better information exists.
        ->and($fresh->error)->not->toContain('Exception');

    Notification::assertSentTo($owner, PostFailedNotification::class);
});

it('does not re-notify the author once a group has already been notified', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $owner = $org->owner;
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => $owner->id,
        'state' => PostState::ERROR,
        'error' => null,
    ]);

    app(MissedPostsService::class)->scan();
    Notification::assertSentToTimes($owner, PostFailedNotification::class, 1);

    app(MissedPostsService::class)->scan();
    Notification::assertSentToTimes($owner, PostFailedNotification::class, 1);
});

it('does not notify or error a group that is still QUEUE', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDay(),
    ]);

    app(MissedPostsService::class)->scan();

    Notification::assertNothingSent();
});

it('does not blow up notifying a group whose author was removed', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => null,
        'state' => PostState::ERROR,
        'error' => null,
    ]);

    app(MissedPostsService::class)->scan();

    expect($group->fresh()->notified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

// --- Retry resets the notify-once flag (review finding 1, CRITICAL) --------

it('resets notified_at on retry, so a group that fails again after being retried is notified again', function () {
    // The bug this proves fixed: a post fails, the scanner emails the
    // author and stamps notified_at; the author clicks Retry; it fails
    // again — without this fix, notifyFailed()'s whereNull('notified_at')
    // filter would skip it forever, and because retry() also clears
    // `error`, buildErrorSummary() would never run again either, so
    // BOTH the email and post_groups.error stay silent for a post that
    // has now failed a second time — reached through the ordinary
    // product flow, no race required.
    Notification::fake();
    Queue::fake();

    $org = Organization::factory()->create();
    $owner = $org->owner;
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => $owner->id,
        'state' => PostState::ERROR,
        'error' => 'first failure summary',
        'notified_at' => now()->subHour(),
    ]);
    Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'failed segment',
        'settings' => [],
        'state' => PostState::ERROR,
    ]);

    // Baseline: already notified once, nothing has happened since — a
    // scan right now must not re-notify.
    app(MissedPostsService::class)->scan();
    Notification::assertNothingSent();

    OrganizationContext::set($org);
    app(PostGroupService::class)->retry(PostGroup::query()->findOrFail($group->id));
    OrganizationContext::clear();

    expect($group->fresh()->notified_at)->toBeNull()
        ->and($group->fresh()->error)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);

    // Simulate the retry failing again — direct fixture mutation, not
    // the full publish pipeline (already exhaustively covered by
    // PublishPostJobTest/RescheduleTest).
    PostGroup::withoutGlobalScope('organization')->whereKey($group->id)->update(['state' => PostState::ERROR->value]);
    Post::withoutGlobalScope('organization')->where('post_group_id', $group->id)->update(['state' => PostState::ERROR->value]);

    app(MissedPostsService::class)->scan();

    Notification::assertSentToTimes($owner, PostFailedNotification::class, 1);
    expect($group->fresh()->error)->not->toBeNull();
});

// --- Notify-once claim survives real concurrency (review finding 4) --------

it('two genuinely concurrent scans claim a failed group\'s notification exactly once', function () {
    // Real, OS-level concurrency (see tests/Pest.php's raceConcurrently()
    // docblock) — proves MissedPostsService::notifyOne()'s conditional
    // `UPDATE ... WHERE notified_at IS NULL` claim (review finding 2's
    // fix) is actually exclusive under a genuine race, not merely
    // "looks right reading the code". Each forked child issues the
    // EXACT same statement notifyOne() does (duplicated here, not
    // called through the private method, purely so each child can
    // report its own affected-row count back to the parent) and writes
    // that count to a results file. Sending is gated 1:1 on that count
    // in production code, so the sum of both counts being exactly 1 is
    // the direct proof at most one notification is ever sent.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Race Owner', 'email' => 'missed-posts-notify-race-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Race Co', 'slug' => 'race-notify-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);
    $group = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
        'state' => PostState::ERROR,
        'error' => 'seed',
        'notified_at' => null,
    ]);

    $resultDir = sys_get_temp_dir().'/missed-posts-notify-race-'.bin2hex(random_bytes(6));
    mkdir($resultDir);

    try {
        $pids = [];

        foreach (range(0, 1) as $i) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork() failed — cannot simulate concurrency.');
            }

            if ($pid === 0) {
                $childConnection = 'race_notify_child_'.$i.'_'.getmypid();
                config(['database.connections.'.$childConnection => config('database.connections.pgsql')]);
                config(['database.default' => $childConnection]);

                $claimed = PostGroup::withoutGlobalScope('organization')
                    ->whereKey($group->id)
                    ->whereNull('notified_at')
                    ->update(['error' => 'seed', 'notified_at' => now()]);

                file_put_contents("{$resultDir}/{$i}", (string) $claimed);

                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[$i] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $claims = array_map(
            fn (int $i): int => (int) trim((string) file_get_contents("{$resultDir}/{$i}")),
            range(0, 1),
        );

        expect(array_sum($claims))->toBe(1);
    } finally {
        foreach (glob("{$resultDir}/*") ?: [] as $file) {
            unlink($file);
        }
        @rmdir($resultDir);
        DB::connection('race')->table('post_groups')->where('id', $group->id)->delete();
        DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});

// --- The actual scheduled entry point --------------------------------------

it('dispatches the scan through ScanMissedPostsJob exactly as the scheduler runs it', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(30));

    OrganizationContext::clear();
    expect(OrganizationContext::get())->toBeNull();

    (new ScanMissedPostsJob)->handle(app(MissedPostsService::class));

    expect(OrganizationContext::get())->toBeNull();
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id);
});
