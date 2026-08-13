<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;

/*
 * Task 20: DELETE /api/posts/{group}. A group is only deletable while
 * nothing in it has actually gone out to a network — see
 * PostGroupService's docblock for the release_id-based rule shared with
 * PUT.
 */

function createDraftGroupForDelete(Organization $org, Integration $integration): PostGroup
{
    $response = test()->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'to delete']]],
        ],
    ]);

    $response->assertCreated();

    return PostGroup::findOrFail($response->json('id'));
}

it('deletes a draft group and cascades to its posts', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroupForDelete($org, $integration);

    $response = $this->deleteJson("/api/posts/{$group->id}");

    $response->assertNoContent();
    expect(PostGroup::query()->find($group->id))->toBeNull();
    expect(Post::query()->where('post_group_id', $group->id)->count())->toBe(0);
});

it('refuses to delete a group that already has a released segment', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroupForDelete($org, $integration);
    Post::query()->where('post_group_id', $group->id)->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-1']);

    $response = $this->deleteJson("/api/posts/{$group->id}");

    $response->assertStatus(409);
    expect(PostGroup::query()->find($group->id))->not->toBeNull();
});

it("404s deleting another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignIntegration = Integration::factory()->for($other)->create(['provider_identifier' => 'fake']);
    actingAsMember($other);
    $foreignGroup = createDraftGroupForDelete($other, $foreignIntegration);

    actingAsMember($org);
    $this->deleteJson("/api/posts/{$foreignGroup->id}")->assertNotFound();
});
