<?php

use App\Enums\OrganizationRole;
use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\PostGroupService;
use App\Services\Posts\PublishService;
use App\Social\Exceptions\ProviderPermanentException;
use App\Support\OrganizationContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Final-review fix batch 1, Criticals 1 and 2: a write landing in the
 * middle of a publish run.
 *
 * Every test here uses real OS-level concurrency (tests/Pest.php's
 * raceConcurrently(), pcntl_fork()) against real Postgres, and drives
 * BOTH sides through the production entry points —
 * PublishService::publishGroup() on one side, PostGroupService's
 * update()/updateDate()/delete() on the other. Nothing here hand-writes
 * the SQL a service is supposed to issue: a racer that locks a row
 * "like publishChannel() does" proves only that SOME lock existed, not
 * that the production predicate, the provider call inside the same
 * transaction, and the commit boundary all behave — which is precisely
 * the gap that let these two Criticals ship.
 *
 * The window is opened by FakeProvider::onPostContaining(), which sleeps
 * INSIDE post() — i.e. inside publishChannel()'s transaction, with its
 * row locks held — exactly where a real provider's HTTP call sits.
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

/**
 * A committed (race-connection) fixture: one organization, one QUEUE
 * group at queue_version 1 due now, and TWO single-segment channels.
 *
 * Both channels' single segment is `order = 0`, exactly as
 * `PostGroupService::replaceChains()` writes them (`order` is assigned
 * per chain, so every channel's first segment is 0). Channel A is still
 * attempted first, and deterministically so, because
 * `PublishService::loadChains()` breaks that tie on `id` — ULIDs, hence
 * creation order, hence A. An earlier revision of this fixture gave B
 * `order = 1` to pin the sequence; that reproduced an ordering
 * production does not actually produce, which is the kind of fixture
 * that quietly stops testing the real code path.
 *
 * @return array{organization: Organization, group: PostGroup, integrationA: Integration, integrationB: Integration, postA: Post, postB: Post, cleanup: Closure(): void}
 */
function publishRaceFixture(string $label): array
{
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, [
        'name' => 'Race Owner',
        'email' => $label.'-'.Str::random(10).'@example.com',
        'password' => 'irrelevant-hash',
    ]);
    $organization = raceCreate(Organization::class, [
        'name' => 'Race Co',
        'slug' => $label.'-'.Str::lower(Str::random(10)),
        'owner_id' => $owner->id,
    ]);
    raceCreate(OrganizationUser::class, [
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationRole::ADMIN,
    ]);

    $integrations = [];
    foreach (['A', 'B'] as $suffix) {
        $integrations[$suffix] = raceCreate(Integration::class, [
            'organization_id' => $organization->id,
            'provider_identifier' => 'fake',
            'internal_id' => (string) Str::ulid(),
            'name' => "race channel {$suffix}",
        ]);
    }

    $group = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'state' => PostState::QUEUE,
        'publish_date' => now(),
        'queue_version' => 1,
    ]);

    $posts = [];
    foreach (['A', 'B'] as $suffix) {
        $posts[$suffix] = raceCreate(Post::class, [
            'post_group_id' => $group->id,
            'organization_id' => $organization->id,
            'integration_id' => $integrations[$suffix]->id,
            'order' => 0,
            'content' => "channel {$suffix} content",
            'settings' => [],
            'state' => PostState::QUEUE,
        ]);
    }

    return [
        'organization' => $organization,
        'group' => $group,
        'integrationA' => $integrations['A'],
        'integrationB' => $integrations['B'],
        'postA' => $posts['A'],
        'postB' => $posts['B'],
        'cleanup' => function () use ($group, $organization, $owner): void {
            DB::connection('race')->table('posts')->where('post_group_id', $group->id)->delete();
            DB::connection('race')->table('post_groups')->where('id', $group->id)->delete();
            DB::connection('race')->table('integrations')->where('organization_id', $organization->id)->delete();
            DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
            DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
            DB::connection('race')->table('users')->where('id', $owner->id)->delete();
        },
    ];
}

/**
 * Runs ONE closure in a forked child (see raceConcurrently()), used for
 * work that must COMMIT: this test process's own connection sits inside
 * RefreshDatabase's never-committed transaction, so any write it makes
 * to a race-connection row holds a lock until teardown — and each test's
 * cleanup, which deletes those rows through the race connection, would
 * then block forever.
 */
function publishRaceRun(Closure $racer): string
{
    return raceConcurrently([$racer])[0];
}

/**
 * A fresh path for a racer to report back through. FakeProvider records
 * what it sent in memory, per process — so the only way to learn how
 * many chains a forked racer actually put on the network is for the
 * racer itself to write the number out.
 */
function publishRaceLog(): string
{
    return sys_get_temp_dir().'/publish-race-'.bin2hex(random_bytes(6));
}

function publishRaceChainsSent(string $log): int
{
    return array_sum(array_map(
        'intval',
        array_filter(explode("\n", (string) file_get_contents($log)), fn (string $line): bool => $line !== ''),
    ));
}

it('an edit landing mid-run never leaves the edited post unpublishable (Critical 1)', function () {
    // The reproduced interleaving, verbatim from the review:
    //
    //  1. W: publishGroup() locks the group (QUEUE, v1, due) and RELEASES
    //     that lock — the commit boundary is per channel, not per run.
    //  2. W: channel A's provider call is in flight, A's rows locked.
    //  3. U: PUT /api/posts/{group} blocks in guardEditable() on A's rows.
    //  4. W: channel A throws ProviderPermanentException -> markAllError
    //     -> COMMIT. No release_id anywhere in the group.
    //  5. U's lock wait resolves — it is ahead of W's channel-B
    //     transaction in the lock queue — hasPublishedSegment is false, so
    //     the edit is allowed: posts deleted and recreated as QUEUE,
    //     queue_version -> 2, publish_date -> +1h, new job dispatched.
    //  6. W: channel B's pending ids no longer exist -> $lockedIds empty.
    //  7. W: finalizeGroupState($group) on the STALE in-memory instance,
    //     outside any transaction, with no re-check of state or
    //     queue_version -> the group is stamped ERROR.
    //  8. The v2 job fires at the new time, sees ERROR != QUEUE, no-ops.
    //     The customer's edited post is lost forever — nothing recovers
    //     it (MissedPostsService::redispatchOrphaned() scans QUEUE only).
    //
    // The assertion that fails without the fix is the group's own state:
    // QUEUE (the edit's generation is still live and still owns the
    // group), never ERROR.
    $fx = publishRaceFixture('c1-edit-midrun');
    $log = publishRaceLog();

    // Handshake, not a delay — see raceAwaitBarrier(). The racer below
    // used to `usleep()` and hope the publish run had locked channel A's
    // rows by then; under load it can lose that guess, and then it does
    // not exercise the race at all, it just asserts against a window that
    // never opened (this is what made RescheduleTest's delete race fail
    // intermittently). The fake provider creates this file on entry to
    // post(), inside the transaction that holds those locks.
    $barrier = sys_get_temp_dir().'/pest-race-barrier-'.bin2hex(random_bytes(6));

    try {
        $racers = [
            // W — the publish run, through the real service.
            function () use ($fx, $barrier) {
                fakeProvider()->onPostContaining(
                    'channel A',
                    new ProviderPermanentException('rejected by the network'),
                    sleepMicroseconds: 400_000,
                    barrierPath: $barrier,
                );

                app(PublishService::class)->publishGroup($fx['group']->id, 1);
            },
            // U — the customer's edit, through the real service. Starts
            // while channel A's provider call is still in flight, so it
            // blocks in guardEditable() on A's rows and is first in the
            // lock queue the instant W commits channel A's failure.
            function () use ($fx, $barrier) {
                raceAwaitBarrier($barrier);
                Queue::fake();
                OrganizationContext::set(Organization::query()->findOrFail($fx['organization']->id));

                app(PostGroupService::class)->update(
                    PostGroup::query()->findOrFail($fx['group']->id),
                    [
                        'type' => 'schedule',
                        'publish_date' => now()->addHour()->toIso8601String(),
                        'posts' => [
                            [
                                'integration_id' => $fx['integrationA']->id,
                                'value' => [['content' => 'channel A edited']],
                            ],
                            [
                                'integration_id' => $fx['integrationB']->id,
                                'value' => [['content' => 'channel B edited']],
                            ],
                        ],
                    ],
                );
            },
        ];

        $results = raceConcurrently($racers);

        expect($results[0])->toBe('ok')
            ->and($results[1])->toBe('ok');

        OrganizationContext::set($fx['organization']);
        $group = PostGroup::query()->whereKey($fx['group']->id)->firstOrFail();
        $posts = Post::query()->where('post_group_id', $group->id)->get();

        expect($group->state)->toBe(PostState::QUEUE)
            ->and($group->queue_version)->toBe(2)
            ->and($posts)->toHaveCount(2)
            ->and($posts->every(fn (Post $post): bool => $post->state === PostState::QUEUE))->toBeTrue()
            ->and($posts->every(fn (Post $post): bool => $post->release_id === null))->toBeTrue();

        // ... and the edit's own generation still publishes when its time
        // comes: the whole point of C1 is that this never happened.
        //
        // Run in a forked child, not here: this test's own connection sits
        // inside RefreshDatabase's never-committed transaction, so
        // publishing from the parent would hold row locks on
        // race-connection rows until teardown — and the cleanup below,
        // which deletes those rows through the race connection, would
        // block on them forever. A child commits normally.
        expect(publishRaceRun(function () use ($group, $log): void {
            Carbon::setTestNow($group->publish_date->addSecond());
            $provider = fakeProvider();

            app(PublishService::class)->publishGroup($group->id, $group->queue_version);

            file_put_contents($log, count($provider->publishedChains())."\n", FILE_APPEND | LOCK_EX);
        }))->toBe('ok');

        expect(publishRaceChainsSent($log))->toBe(2)
            ->and(PostGroup::query()->whereKey($group->id)->firstOrFail()->state)->toBe(PostState::PUBLISHED)
            ->and(Post::query()->where('post_group_id', $group->id)->whereNull('release_id')->exists())->toBeFalse();
    } finally {
        @unlink($barrier);
        @unlink($log);
        $fx['cleanup']();
    }
});

it('a calendar drag landing mid-run never publishes the dragged channel at the old time (Critical 2)', function () {
    // Same window as C1, via updateDate() instead of update(). Steps 1-5
    // are identical (the drag wins the lock queue the instant channel A's
    // permanent failure commits, and is allowed through because nothing
    // in the group carries a release_id yet), then:
    //
    //  6. W enters channel B's transaction and locks its rows on
    //     `release_id IS NULL AND state = QUEUE` — BOTH still true after a
    //     reschedule, which touches post_groups only — calls the provider
    //     and commits release_id. The post is irrevocably live an hour
    //     early; queue_version and publish_date were re-read exactly once,
    //     in lockGroupIfQueued(), before any of this happened.
    //
    // The assertion that fails without the fix: channel B's release_id.
    $fx = publishRaceFixture('c2-drag-midrun');
    $log = publishRaceLog();

    // Handshake, not a delay — see raceAwaitBarrier(). The racer below
    // used to `usleep()` and hope the publish run had locked channel A's
    // rows by then; under load it can lose that guess, and then it does
    // not exercise the race at all, it just asserts against a window that
    // never opened (this is what made RescheduleTest's delete race fail
    // intermittently). The fake provider creates this file on entry to
    // post(), inside the transaction that holds those locks.
    $barrier = sys_get_temp_dir().'/pest-race-barrier-'.bin2hex(random_bytes(6));

    try {
        $racers = [
            // W — the publish run for schedule generation v1.
            function () use ($fx, $barrier) {
                fakeProvider()
                    ->onPostContaining(
                        'channel A',
                        new ProviderPermanentException('rejected by the network'),
                        sleepMicroseconds: 400_000,
                        barrierPath: $barrier,
                    );

                app(PublishService::class)->publishGroup($fx['group']->id, 1);
            },
            // U — the calendar drag, through the real service.
            function () use ($fx, $barrier) {
                raceAwaitBarrier($barrier);
                Queue::fake();
                OrganizationContext::set(Organization::query()->findOrFail($fx['organization']->id));

                app(PostGroupService::class)->updateDate(
                    PostGroup::query()->findOrFail($fx['group']->id),
                    now()->addHour(),
                );
            },
        ];

        $results = raceConcurrently($racers);

        expect($results[0])->toBe('ok')
            ->and($results[1])->toBe('ok');

        OrganizationContext::set($fx['organization']);
        $group = PostGroup::query()->whereKey($fx['group']->id)->firstOrFail();
        $postB = Post::query()->whereKey($fx['postB']->id)->firstOrFail();

        expect($postB->release_id)->toBeNull()
            ->and($postB->state)->toBe(PostState::QUEUE)
            ->and($group->queue_version)->toBe(2)
            ->and($group->publish_date->isAfter(now()->addMinutes(30)))->toBeTrue()
            // The dragged generation still owns the group: a superseded
            // run must not settle it (that is C1's half of the same fix).
            ->and($group->state)->toBe(PostState::QUEUE);

        // The drag's own generation publishes channel B at the NEW time
        // (in a forked child — see the identical note in the C1 test).
        expect(publishRaceRun(function () use ($group, $log): void {
            Carbon::setTestNow($group->publish_date->addSecond());
            $provider = fakeProvider();

            app(PublishService::class)->publishGroup($group->id, $group->queue_version);

            file_put_contents(
                $log,
                count($provider->publishedChains()).':'.($provider->publishedChains()[0][0]->content ?? '')."\n",
                FILE_APPEND | LOCK_EX,
            );
        }))->toBe('ok');

        expect(trim((string) file_get_contents($log)))->toBe('1:channel B content')
            ->and(Post::query()->whereKey($postB->id)->firstOrFail()->release_id)->not->toBeNull();
    } finally {
        @unlink($barrier);
        @unlink($log);
        $fx['cleanup']();
    }
});

it('two genuinely concurrent publishGroup() runs for the same group publish each segment exactly once', function () {
    // The case the per-channel row locks exist for, and which had no test
    // at all: two workers running the same group at the same time (Task
    // 31's force-released ShouldBeUnique lock makes this genuinely
    // reachable — see PostGroupService::dispatchPublishIfQueued()).
    //
    // Both racers drive the real publishGroup(). Each writes back how
    // many chains ITS OWN provider instance actually sent — FakeProvider's
    // recording is per-process, so the sum across both children is the
    // true number of network calls that went out for this group. Exactly
    // one per channel is the only acceptable answer: a regression that
    // drops the `state = QUEUE` predicate from publishChannel()'s
    // $lockedIds re-lock, or moves the provider call outside that
    // transaction, makes this sum climb.
    $fx = publishRaceFixture('concurrent-publish');
    $log = publishRaceLog();

    try {
        $racer = function () use ($fx, $log) {
            $provider = fakeProvider()
                ->onPostContaining('channel A', sleepMicroseconds: 250_000)
                ->onPostContaining('channel B', sleepMicroseconds: 250_000);

            app(PublishService::class)->publishGroup($fx['group']->id, 1);

            file_put_contents($log, count($provider->publishedChains())."\n", FILE_APPEND | LOCK_EX);
        };

        $results = raceConcurrently([$racer, $racer]);

        expect($results[0])->toBe('ok')
            ->and($results[1])->toBe('ok');

        expect(publishRaceChainsSent($log))->toBe(2);

        OrganizationContext::set($fx['organization']);
        $group = PostGroup::query()->whereKey($fx['group']->id)->firstOrFail();
        $posts = Post::query()->where('post_group_id', $group->id)->get();

        expect($group->state)->toBe(PostState::PUBLISHED)
            ->and($posts)->toHaveCount(2)
            ->and($posts->every(fn (Post $post): bool => $post->state === PostState::PUBLISHED))->toBeTrue()
            ->and($posts->pluck('release_id')->filter()->unique())->toHaveCount(2);
    } finally {
        @unlink($log);
        $fx['cleanup']();
    }
});
