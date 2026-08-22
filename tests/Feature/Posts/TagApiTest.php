<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
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

/*
 * Task 1.3 builds the tag-management UI, and its delete confirmation is
 * supposed to say how many posts the tag will come off. Deleting a tag
 * cascades `post_group_tag` at the database level and never fails, so
 * without a count from the server that warning would be a guess. These
 * two cases pin the count down — and pin down that it stays out of the
 * calendar's copy of the same resource.
 */

it('reports how many post groups each tag is attached to', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $used = Tag::factory()->for($org)->create(['name' => 'Used']);
    $unused = Tag::factory()->for($org)->create(['name' => 'Unused']);

    PostGroup::factory()->for($org)->count(2)->create()
        ->each(fn (PostGroup $group) => $group->tags()->attach($used));

    $tags = collect((array) $this->getJson('/api/tags')->assertOk()->json())->keyBy('id');

    expect($tags[$used->id]['post_groups_count'])->toBe(2)
        ->and($tags[$unused->id]['post_groups_count'])->toBe(0);
});

it('does not leak the usage count into the calendar, which shares TagResource', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $tag = Tag::factory()->for($org)->create();

    $group = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($group, 'group')->create(['organization_id' => $org->id, 'integration_id' => $integration->id]);
    $group->tags()->attach($tag);

    $entries = (array) $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
    ]))->assertOk()->json();

    // whenCounted(), not an unconditional field: the count is not loaded
    // here, and a card that carried it would both query a missing
    // attribute and grow every calendar payload for nothing.
    expect($entries[0]['tags'][0])->not->toHaveKey('post_groups_count');
});

/*
 * The duplicate-name message was a hardcoded English literal. Nothing
 * showed it until the SPA gained tag CRUD, at which point it became the
 * one error a Russian-locale user would meet in English.
 */
it('returns the duplicate-name message in the caller\'s locale', function () {
    $org = Organization::factory()->create();
    // The locale comes off the signed-in user — SetLocale middleware
    // reads `users.locale` first and would overwrite anything this test
    // set with App::setLocale() before the request reached validation.
    actingAsMember($org)->update(['locale' => 'ru']);
    Tag::factory()->for($org)->create(['name' => 'Launch']);

    $response = $this->postJson('/api/tags', ['name' => 'launch', 'color' => '#ff00aa']);

    $response->assertStatus(422);
    expect($response->json('errors.name.0'))
        ->toBe(trans('validation.tag_name_unique', locale: 'ru'))
        ->and($response->json('errors.name.0'))->not->toBe(trans('validation.tag_name_unique', locale: 'en'));
});
