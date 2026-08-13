<?php

use App\Models\Organization;
use App\Models\Tag;

/*
 * Task 21: GET|POST|PUT|DELETE /api/tags. Tag colours are user input
 * later rendered into HTML by Task 24, so the format is restricted to a
 * plain 6-digit hex string rather than accepting arbitrary strings.
 */

it('creates, lists, updates and deletes a tag', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $create = $this->postJson('/api/tags', ['name' => 'Launch', 'color' => '#ff00aa']);
    $create->assertCreated();
    $id = $create->json('id');

    $index = $this->getJson('/api/tags');
    $index->assertOk();
    expect(collect((array) $index->json())->pluck('id'))->toContain($id);

    $update = $this->putJson("/api/tags/{$id}", ['name' => 'Launch v2', 'color' => '#00ff00']);
    $update->assertOk();
    expect($update->json('name'))->toBe('Launch v2');

    $destroy = $this->deleteJson("/api/tags/{$id}");
    $destroy->assertNoContent();
    expect(Tag::query()->find($id))->toBeNull();
});

it('rejects a tag colour that is not a well-formed hex string', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $response = $this->postJson('/api/tags', ['name' => 'Bad', 'color' => 'red']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['color']);
});

it('rejects a duplicate tag name case-insensitively within the organization', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    Tag::factory()->for($org)->create(['name' => 'Launch']);

    $response = $this->postJson('/api/tags', ['name' => 'launch', 'color' => '#ff00aa']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['name']);
});

it('does not allow a tag id from another organization to be updated', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $otherOrg = Organization::factory()->create();
    $foreignTag = Tag::factory()->for($otherOrg)->create();

    $response = $this->putJson("/api/tags/{$foreignTag->id}", ['name' => 'Hijacked', 'color' => '#ff00aa']);

    $response->assertNotFound();
});
