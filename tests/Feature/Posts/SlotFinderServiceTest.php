<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\IntegrationTimeSlot;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\SlotFinderService;
use App\Support\OrganizationContext;
use Illuminate\Support\Carbon;

/*
 * Task 21: SlotFinderService. `minutes_from_week_start` is anchored to
 * Monday 00:00 UTC (see resources/js/utils/timeSlots.ts' docblock, which
 * Task 17 established for the editor) — the slot finder must agree with
 * that anchor exactly, so these tests pin "now" to a known UTC instant
 * and assert against UTC instants directly, never depending on the
 * machine's local timezone.
 *
 * Deliberately placed under tests/Feature (not tests/Unit, despite the
 * task-21 brief's suggested path): it needs real Integration/PostGroup/
 * Post rows and RefreshDatabase, and tests/Unit/Posts/PostStateMachineTest.php's
 * docblock documents that adding RefreshDatabase ad hoc to a tests/Unit
 * file previously corrupted rollback for every test after it in the same
 * process — Feature is where every other DB-touching Posts test already
 * lives.
 */

afterEach(function () {
    OrganizationContext::clear();
    Carbon::setTestNow();
});

it('returns the next free slot skipping occupied ones', function () {
    $org = Organization::factory()->create();
    OrganizationContext::set($org);

    // Freeze "now" at Monday 2026-08-10 08:00 UTC, so both of this
    // week's slots (10:00 and 14:00) are still in the future.
    Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));

    $integration = Integration::factory()->for($org)->create();
    IntegrationTimeSlot::factory()->for($integration)->create(['minutes_from_week_start' => 10 * 60]);
    IntegrationTimeSlot::factory()->for($integration)->create(['minutes_from_week_start' => 14 * 60]);

    $group = PostGroup::factory()->for($org)->create([
        'state' => PostState::QUEUE,
        'publish_date' => Carbon::parse('2026-08-10 10:00:00', 'UTC'),
    ]);
    Post::factory()->for($group, 'group')->create([
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => PostState::QUEUE,
    ]);

    $slot = app(SlotFinderService::class)->findNextFreeSlot($integration->id);

    expect($slot->eq(Carbon::parse('2026-08-10 14:00:00', 'UTC')))->toBeTrue();
});

it('falls back to now plus one hour when no slots are configured', function () {
    $org = Organization::factory()->create();
    OrganizationContext::set($org);

    Carbon::setTestNow(Carbon::parse('2026-08-10 08:03:00', 'UTC'));

    $integration = Integration::factory()->for($org)->create();

    $slot = app(SlotFinderService::class)->findNextFreeSlot($integration->id);

    // now()+1h = 09:03, rounded up to the next 5-minute mark = 09:05.
    expect($slot->eq(Carbon::parse('2026-08-10 09:05:00', 'UTC')))->toBeTrue();
});
