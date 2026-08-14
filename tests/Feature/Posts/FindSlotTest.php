<?php

use App\Models\Integration;
use App\Models\Organization;

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

it('rejects an integration_id belonging to another organization', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $otherOrg = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($otherOrg)->create();

    $response = $this->getJson("/api/posts/find-slot?integration_id={$foreignIntegration->id}");

    $response->assertForbidden();
});
