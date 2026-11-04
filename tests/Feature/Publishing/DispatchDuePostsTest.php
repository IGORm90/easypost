<?php

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\DueDispatchService;
use App\Support\OrganizationContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Durable-scheduling rewrite: DueDispatchService is the atomic claim
 * step behind `posts:dispatch-due` (see DispatchDuePostsCommand). It is
 * the only thing standing between "a row sitting in Postgres with
 * state=SCHEDULED" and "a PublishPostJob actually exists" — nothing is
 * queued at save time any more (see PostGroupService::resolveTargetState()).
 *
 * Runs with NO OrganizationContext set, same as MissedPostsService — a
 * scheduled command has no tenant.
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

function scheduledGroup(Organization $org, ?DateTimeInterface $publishDate = null): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::SCHEDULED,
        'publish_date' => $publishDate ?? now()->addSeconds(30),
        'queue_version' => 1,
    ]);
}

it('claims a group due within the lookahead window and dispatches PublishPostJob', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $group = scheduledGroup($org, now()->addSeconds(30));

    app(DueDispatchService::class)->dispatchDue();

    expect($group->fresh()->state)->toBe(PostState::QUEUE)
        ->and($group->fresh()->last_dispatched_at)->not->toBeNull()
        ->and($group->fresh()->dispatch_attempts)->toBe(1);

    Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === 1
    );
});

it('dispatches with a delay matching the claimed row\'s publish_date, so an early claim does not run before it is due', function () {
    // P0 fix: a row claimed up to `dispatch_lookahead_seconds` before its
    // publish_date must not run immediately — PostGroupLifecycle::isDueNow()
    // only tolerates 5s of future slack, so an undelayed dispatch would
    // un-claim itself back to SCHEDULED the instant it ran, bouncing
    // until a later tick happened to land within the grace window. The
    // delay is bounded by the lookahead (~90s by default), never
    // unbounded — this is not a return to the old "weeks in Redis" bug.
    Queue::fake();
    $org = Organization::factory()->create();
    $publishDate = now()->addSeconds(60);
    $group = scheduledGroup($org, $publishDate);

    app(DueDispatchService::class)->dispatchDue();

    Queue::assertPushed(
        PublishPostJob::class,
        fn (PublishPostJob $job): bool => $job->postGroupId === $group->id
            && $job->delay instanceof CarbonInterface
            && $job->delay->isSameSecond($publishDate)
    );
});

it('dispatches with no meaningful delay when the claimed row is already due', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $publishDate = now()->subSecond();
    $group = scheduledGroup($org, $publishDate);

    app(DueDispatchService::class)->dispatchDue();

    Queue::assertPushed(
        PublishPostJob::class,
        fn (PublishPostJob $job): bool => $job->postGroupId === $group->id
    );
});

it('does not claim a group outside the lookahead window', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $group = scheduledGroup($org, now()->addMinutes(10));

    app(DueDispatchService::class)->dispatchDue();

    expect($group->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($group->fresh()->last_dispatched_at)->toBeNull();

    Queue::assertNotPushed(PublishPostJob::class);
});

it('does not claim a DRAFT group', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::DRAFT,
        'publish_date' => now()->addSeconds(30),
    ]);

    app(DueDispatchService::class)->dispatchDue();

    expect($group->fresh()->state)->toBe(PostState::DRAFT);
    Queue::assertNotPushed(PublishPostJob::class);
});

it('flips the group\'s pending posts to QUEUE alongside the group itself', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $group = scheduledGroup($org, now()->addSeconds(30));
    $integration = Integration::factory()->create(['organization_id' => $group->organization_id]);
    $post = Post::factory()->create([
        'post_group_id' => $group->id,
        'organization_id' => $group->organization_id,
        'integration_id' => $integration->id,
        'state' => PostState::SCHEDULED,
    ]);

    app(DueDispatchService::class)->dispatchDue();

    expect($post->fresh()->state)->toBe(PostState::QUEUE);
});

it('claims across organizations with no tenant context set', function () {
    Queue::fake();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $a = scheduledGroup($orgA);
    $b = scheduledGroup($orgB);

    $claimed = app(DueDispatchService::class)->dispatchDue();

    expect($claimed)->toBe(2)
        ->and($a->fresh()->state)->toBe(PostState::QUEUE)
        ->and($b->fresh()->state)->toBe(PostState::QUEUE);
});

it('respects the configured batch size, claiming in bounded batches', function () {
    Queue::fake();
    config(['publishing.dispatch_batch_size' => 2, 'publishing.dispatch_max_batches' => 5]);
    $org = Organization::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        scheduledGroup($org);
    }

    $claimed = app(DueDispatchService::class)->dispatchDue();

    expect($claimed)->toBe(5);
    Queue::assertPushed(PublishPostJob::class, 5);
});

it('two concurrent dispatch passes claim disjoint sets — no row is claimed twice', function () {
    // Real OS-level concurrency against real Postgres, matching
    // PublishRaceTest's own pattern: raceConcurrently()/raceCreate() —
    // fixtures created on the "race" (autocommit) connection so the
    // forked children, each on their own fresh connection, can actually
    // see them (RefreshDatabase's open transaction on the default
    // connection is invisible to a separate connection).
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, [
        'name' => 'Race Owner',
        'email' => 'race-'.Str::random(10).'@example.com',
        'password' => 'irrelevant-hash',
    ]);
    $org = raceCreate(Organization::class, [
        'name' => 'Race Co',
        'slug' => 'race-'.Str::lower(Str::random(10)),
        'owner_id' => $owner->id,
    ]);
    $groups = collect(range(1, 10))->map(fn () => raceCreate(PostGroup::class, [
        'organization_id' => $org->id,
        'state' => PostState::SCHEDULED,
        'publish_date' => now()->addSeconds(30),
        'queue_version' => 1,
        'created_by' => $owner->id,
    ]));

    $racer = function () {
        Queue::fake();
        app(DueDispatchService::class)->dispatchDue();
    };

    raceConcurrently([$racer, $racer]);

    $states = $groups->map(fn (PostGroup $g) => $g->fresh()->state)->all();
    expect($states)->each->toBe(PostState::QUEUE);

    $attempts = $groups->map(fn (PostGroup $g) => $g->fresh()->dispatch_attempts)->all();
    // Every row claimed by exactly one of the two concurrent passes —
    // never claimed (and therefore incremented) twice.
    expect($attempts)->each->toBe(1);

    DB::connection('race')->table('post_groups')->where('organization_id', $org->id)->delete();
    DB::connection('race')->table('organization_user')->where('organization_id', $org->id)->delete();
    DB::connection('race')->table('organizations')->where('id', $org->id)->delete();
    DB::connection('race')->table('users')->where('id', $owner->id)->delete();
});
