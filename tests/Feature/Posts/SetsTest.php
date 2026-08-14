<?php

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Set;

/*
 * Task 23: GET|POST|PUT|DELETE /api/sets. A set is a named, reusable
 * bundle of integration ids — {name, integration_ids[]} — used later
 * (Task 25) to preselect channels in the post editor.
 */

it('creates, lists, updates and deletes a set', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integrationA = Integration::factory()->for($org)->create();
    $integrationB = Integration::factory()->for($org)->create();

    $create = $this->postJson('/api/sets', [
        'name' => 'Launch channels',
        'integration_ids' => [$integrationA->id],
    ]);
    $create->assertCreated();
    $id = $create->json('id');
    expect($create->json('integration_ids'))->toBe([$integrationA->id]);

    $index = $this->getJson('/api/sets');
    $index->assertOk();
    expect(collect((array) $index->json())->pluck('id'))->toContain($id);

    $update = $this->putJson("/api/sets/{$id}", [
        'name' => 'Launch channels v2',
        'integration_ids' => [$integrationA->id, $integrationB->id],
    ]);
    $update->assertOk();
    expect($update->json('name'))->toBe('Launch channels v2');
    expect($update->json('integration_ids'))->toEqualCanonicalizing([$integrationA->id, $integrationB->id]);

    $destroy = $this->deleteJson("/api/sets/{$id}");
    $destroy->assertNoContent();
    expect(Set::query()->find($id))->toBeNull();
});

it('rejects a set referencing another organization\'s integration with 422', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $otherOrg = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($otherOrg)->create();

    $response = $this->postJson('/api/sets', [
        'name' => 'Sneaky set',
        'integration_ids' => [$foreignIntegration->id],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['integration_ids.0']);
    expect(Set::query()->count())->toBe(0);
});

it('does not allow a set id from another organization to be updated', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $ownIntegration = Integration::factory()->for($org)->create();
    $otherOrg = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($otherOrg)->create();
    $foreignSet = Set::factory()->for($otherOrg)->create(['content' => ['integration_ids' => [$foreignIntegration->id]]]);

    $response = $this->putJson("/api/sets/{$foreignSet->id}", [
        'name' => 'Hijacked',
        'integration_ids' => [$ownIntegration->id],
    ]);

    $response->assertNotFound();
});

it('silently drops an integration id from a set once that integration is deleted', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create();
    $set = Set::factory()->for($org)->create(['content' => ['integration_ids' => [$integration->id]]]);

    $integration->delete();

    $show = $this->getJson('/api/sets');
    $show->assertOk();
    $body = collect((array) $show->json())->firstWhere('id', $set->id);
    expect($body['integration_ids'])->toBe([]);
});
