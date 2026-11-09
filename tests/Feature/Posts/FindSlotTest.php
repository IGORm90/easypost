<?php

use App\Models\Integration;
use App\Models\IntegrationTimeSlot;
use App\Models\Organization;
use Illuminate\Support\Str;

/*
 * Task 21: GET /api/posts/find-slot. Full HTTP-level coverage of the
 * fallback path (the "no slots configured" branch of SlotFinderService,
 * already unit-covered in tests/Feature/Posts/SlotFinderServiceTest.php)
 * plus cross-tenant authorization for the `integration_id` query param.
 */

it('returns a publish_date for a channel with no configured slots', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create();

    $response = $this->getJson("/api/posts/find-slot?integration_id={$integration->id}");

    $response->assertOk();
    expect($response->json('publish_date'))->not->toBeNull();
});

it('refuses an integration_id belonging to another organization exactly like one that does not exist', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $otherOrg = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($otherOrg)->create();

    $foreignResponse = $this->getJson("/api/posts/find-slot?integration_id={$foreignIntegration->id}");
    $missingResponse = $this->getJson('/api/posts/find-slot?integration_id='.Str::ulid());

    // 422 for both, with the same error: the status must not tell a caller whether an
    // integration id exists in some other organization.
    $foreignResponse->assertStatus(422)->assertJsonValidationErrors(['integration_id']);
    $missingResponse->assertStatus(422)->assertJsonValidationErrors(['integration_id']);
    expect($foreignResponse->json('errors'))->toBe($missingResponse->json('errors'));
});

it('answers several free slots when asked for a count, keeping publish_date as the first one', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create();
    IntegrationTimeSlot::factory()->for($integration)->create(['minutes_from_week_start' => 10 * 60]);
    IntegrationTimeSlot::factory()->for($integration)->create(['minutes_from_week_start' => 14 * 60]);

    $response = $this->getJson("/api/posts/find-slot?integration_id={$integration->id}&count=3");

    $response->assertOk();
    expect($response->json('publish_dates'))->toHaveCount(3);
    expect($response->json('publish_date'))->toBe($response->json('publish_dates.0'));
});

it('still answers a single slot in publish_dates when no count is given', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create();

    $response = $this->getJson("/api/posts/find-slot?integration_id={$integration->id}");

    expect($response->json('publish_dates'))->toHaveCount(1);
});

it('refuses a count outside 1..8', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create();

    $this->getJson("/api/posts/find-slot?integration_id={$integration->id}&count=9")
        ->assertStatus(422)->assertJsonValidationErrors(['count']);
    $this->getJson("/api/posts/find-slot?integration_id={$integration->id}&count=0")
        ->assertStatus(422)->assertJsonValidationErrors(['count']);
});
