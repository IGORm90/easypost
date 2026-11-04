<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\PublishService;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\Log;

/*
 * Post-review fix (P1): `0001_01_01_000027_add_durable_scheduling_to_post_groups_table`
 * used to backfill QUEUE -> SCHEDULED inside the migration itself, which
 * broke the "additive only" migration rule and opened a real
 * old-code-meets-migrated-data window during deploy (see that migration's
 * docblock, and this command's docblock). This command is the replacement
 * — a manual, one-off, idempotent step run AFTER the new code is live.
 *
 * Runs with NO OrganizationContext set, same as every other cross-tenant
 * scheduled-publishing sweep in this codebase.
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

it('converts every QUEUE group to SCHEDULED, cross-tenant', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $a = PostGroup::factory()->create(['organization_id' => $orgA->id, 'state' => PostState::QUEUE]);
    $b = PostGroup::factory()->create(['organization_id' => $orgB->id, 'state' => PostState::QUEUE]);
    $draft = PostGroup::factory()->create(['organization_id' => $orgA->id, 'state' => PostState::DRAFT]);

    $this->artisan('posts:backfill-durable-scheduling')->assertSuccessful();

    expect($a->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($b->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($draft->fresh()->state)->toBe(PostState::DRAFT);
});

it('converts pending (release_id null) QUEUE posts to SCHEDULED, but leaves an already-released post QUEUE', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = PostGroup::factory()->create(['organization_id' => $org->id, 'state' => PostState::QUEUE]);
    $pending = Post::factory()->create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => PostState::QUEUE,
        'release_id' => null,
    ]);
    $released = Post::factory()->create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => PostState::QUEUE,
        'release_id' => 'already-sent',
        'order' => 1,
    ]);

    $this->artisan('posts:backfill-durable-scheduling')->assertSuccessful();

    expect($pending->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($released->fresh()->state)->toBe(PostState::QUEUE);
});

it('is a no-op on a second run', function () {
    $org = Organization::factory()->create();
    PostGroup::factory()->create(['organization_id' => $org->id, 'state' => PostState::QUEUE]);

    $this->artisan('posts:backfill-durable-scheduling')->assertSuccessful();
    $this->artisan('posts:backfill-durable-scheduling')->assertSuccessful();

    expect(PostGroup::withoutGlobalScope('organization')->where('state', PostState::QUEUE)->count())->toBe(0)
        ->and(PostGroup::withoutGlobalScope('organization')->where('state', PostState::SCHEDULED)->count())->toBe(1);
});

it('logs the number of rows converted', function () {
    Log::shouldReceive('info')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'posts.backfill_durable_scheduling'
            && $context['post_groups'] === 1
    );

    $org = Organization::factory()->create();
    PostGroup::factory()->create(['organization_id' => $org->id, 'state' => PostState::QUEUE]);

    $this->artisan('posts:backfill-durable-scheduling')->assertSuccessful();
});

// --- Post-review fix (P1): never touch a row the new code could have claimed

it('converts a genuine legacy QUEUE row but leaves a concurrently-claimed live QUEUE row completely untouched', function () {
    // The safety property this proves: dispatch_attempts=0 is a marker
    // ONLY a pre-migration row can carry, because every new-code claim
    // path (DueDispatchService::claimBatch(), PostGroupService::
    // dispatch(), MissedPostsService::dispatchRedispatch())
    // increments dispatch_attempts in the SAME statement that sets
    // state='QUEUE'. A row genuinely claimed moments before this command
    // runs — dispatch_attempts=1 — must never be touched, however soon
    // after the new dispatcher went live this command happens to run.
    $org = Organization::factory()->create();
    $legacy = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'dispatch_attempts' => 0,
    ]);
    $live = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'dispatch_attempts' => 1,
        'last_dispatched_at' => now(),
    ]);

    $this->artisan('posts:backfill-durable-scheduling')->assertSuccessful();

    expect($legacy->fresh()->state)->toBe(PostState::SCHEDULED)
        ->and($live->fresh()->state)->toBe(PostState::QUEUE)
        ->and($live->fresh()->dispatch_attempts)->toBe(1);
});

it('does not strand a group that is finishing publication concurrently with a backfill run', function () {
    // Simulates the exact race the review found: PublishService::
    // finalizeGroupState() re-checks state === QUEUE before transitioning
    // to PUBLISHED. If the backfill's UPDATE landed on this group first
    // (because it were touching live rows), that re-check would silently
    // decline to finalize, stranding a group whose posts already went
    // out at SCHEDULED forever. Guarded against here by dispatch_attempts
    // >= 1 keeping this exact row out of the backfill's WHERE clause.
    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'dispatch_attempts' => 1,
        'queue_version' => 1,
        'last_dispatched_at' => now(),
        'publish_date' => now()->subMinute(),
    ]);
    // Posts already published — as if publishLockedChannel() committed
    // for every channel and finalizeGroupState() has not yet run.
    Post::factory()->create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => PostState::PUBLISHED,
        'release_id' => 'already-sent',
    ]);

    $this->artisan('posts:backfill-durable-scheduling')->assertSuccessful();

    expect($group->fresh()->state)->toBe(PostState::QUEUE);

    OrganizationContext::set($org);
    app(PublishService::class)->publishGroup($group->id, 1);

    expect($group->fresh()->state)->toBe(PostState::PUBLISHED);
});
