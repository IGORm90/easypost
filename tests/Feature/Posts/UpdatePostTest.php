<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;

/*
 * Task 20: GET/PUT/PATCH .../date on a post group. PUT is a full chain
 * replace; the interesting behaviour is what it refuses to do once any
 * segment already carries a release_id (the anti-duplication marker
 * Task 18's `posts_release_id_immutable` trigger protects — see
 * Post::RELEASE_ID_IMMUTABLE_SQLSTATE).
 */

function createDraftGroup(Organization $org, Integration $integration, string $content = 'original'): PostGroup
{
    $response = test()->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => $content]]],
        ],
    ]);

    $response->assertCreated();

    return PostGroup::findOrFail($response->json('id'));
}

it('shows a post group with its chains grouped by integration', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration);

    $response = $this->getJson("/api/posts/{$group->id}");

    $response->assertOk();
    $response->assertJsonPath('id', $group->id);
    $response->assertJsonPath('posts.0.integration_id', $integration->id);
    $response->assertJsonPath('posts.0.value.0.content', 'original');
});

it("404s reading another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($other)->create(['provider_identifier' => 'fake']);
    actingAsMember($other);
    $foreignGroup = createDraftGroup($other, $foreignIntegration);

    actingAsMember($org);
    $this->getJson("/api/posts/{$foreignGroup->id}")->assertNotFound();
});

it('fully replaces the chains on PUT', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration, 'before');

    $response = $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'after one'], ['content' => 'after two']]],
        ],
    ]);

    $response->assertOk();

    $posts = Post::query()->where('post_group_id', $group->id)->orderBy('order')->get();
    expect($posts)->toHaveCount(2)
        ->and($posts[0]->content)->toBe('after one')
        ->and($posts[1]->content)->toBe('after two')
        ->and($posts[1]->parent_post_id)->toBe($posts[0]->id);
});

it('moves a draft to QUEUE on PUT when type becomes schedule', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration);

    $response = $this->putJson("/api/posts/{$group->id}", [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'now scheduled']]],
        ],
    ]);

    $response->assertOk();
    expect($group->fresh()->state)->toBe(PostState::QUEUE);
    expect(Post::sole()->state)->toBe(PostState::QUEUE);
});

it('refuses to move an already-queued group back to draft', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration);
    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'A']]]],
    ])->assertOk();

    $response = $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'B']]]],
    ]);

    $response->assertStatus(422);
    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

it("forbids PUT scheduling into another organization's integration", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration);

    $other = Organization::factory()->create();
    $foreign = Integration::factory()->for($other)->create(['provider_identifier' => 'fake']);

    $response = $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $foreign->id, 'value' => [['content' => 'A']]]],
    ]);

    $response->assertForbidden();
    expect(Post::sole()->content)->toBe('original');
});

it("404s PUT on another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($other)->create(['provider_identifier' => 'fake']);
    actingAsMember($other);
    $foreignGroup = createDraftGroup($other, $foreignIntegration);

    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $this->putJson("/api/posts/{$foreignGroup->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'A']]]],
    ])->assertNotFound();
});

it('refuses to PUT a group that already has a released segment', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration);
    Post::query()->where('post_group_id', $group->id)->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);

    $response = $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'new content']]]],
    ]);

    $response->assertStatus(409);
    expect(Post::sole()->release_id)->toBe('remote-1');
});

it('rejects a publish_date in the past on PATCH .../date', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration);

    $response = $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => now()->subDay()->toIso8601String(),
    ]);

    $response->assertStatus(422);
});

it('moves the publish_date on PATCH .../date', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration);
    $newDate = now()->addDays(3);

    $response = $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => $newDate->toIso8601String(),
    ]);

    $response->assertOk();
    expect($group->fresh()->publish_date->diffInSeconds($newDate))->toBeLessThan(1);
});

it("404s PATCH date on another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($other)->create(['provider_identifier' => 'fake']);
    actingAsMember($other);
    $foreignGroup = createDraftGroup($other, $foreignIntegration);

    actingAsMember($org);
    $this->patchJson("/api/posts/{$foreignGroup->id}/date", [
        'publish_date' => now()->addDay()->toIso8601String(),
    ])->assertNotFound();
});

it('duplicates a group into a fresh DRAFT copy', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration, 'dup me');
    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'dup me']]]],
    ])->assertOk();

    $response = $this->postJson("/api/posts/{$group->id}/duplicate");

    $response->assertCreated();
    $newId = $response->json('id');
    expect($newId)->not->toBe($group->id);

    $newGroup = PostGroup::findOrFail($newId);
    expect($newGroup->state)->toBe(PostState::DRAFT);

    $newPost = Post::query()->where('post_group_id', $newGroup->id)->sole();
    expect($newPost->content)->toBe('dup me')
        ->and($newPost->state)->toBe(PostState::DRAFT)
        ->and($newPost->release_id)->toBeNull();

    expect(Post::query()->where('post_group_id', $group->id)->count())->toBe(1);
});

it("404s duplicating another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($other)->create(['provider_identifier' => 'fake']);
    actingAsMember($other);
    $foreignGroup = createDraftGroup($other, $foreignIntegration);

    actingAsMember($org);
    $this->postJson("/api/posts/{$foreignGroup->id}/duplicate")->assertNotFound();
});
