<?php

use App\Enums\PostState;
use App\Enums\RepeatUnit;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Support\Facades\Queue;

/*
 * Task 20: GET/PUT/PATCH .../date on a post group. PUT is a full chain
 * replace; the interesting behaviour is what it refuses to do once any
 * segment already carries a release_id (the anti-duplication marker
 * Task 18's `posts_release_id_immutable` trigger protects — see
 * Post::RELEASE_ID_IMMUTABLE_SQLSTATE).
 */

// Task 30: PostGroupService now dispatches PublishPostJob whenever a
// group enters QUEUE (type=schedule/now) — see CreatePostTest's
// identical note.
beforeEach(fn () => Queue::fake());

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

it('allows retrying an ERROR group by moving it back to QUEUE on PUT', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = createDraftGroup($org, $integration, 'failed attempt');
    // No release_id anywhere: the group genuinely never published, it
    // just failed before reaching the network — the exact case
    // ERROR->QUEUE (PostStateMachine's declared retry transition) exists
    // for.
    $group->update(['state' => PostState::ERROR, 'error' => 'provider timed out']);
    Post::query()->where('post_group_id', $group->id)->update(['state' => PostState::ERROR]);

    $response = $this->putJson("/api/posts/{$group->id}", [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'retry']]]],
    ]);

    $response->assertOk();
    expect($group->fresh()->state)->toBe(PostState::QUEUE);
    expect(Post::sole()->state)->toBe(PostState::QUEUE);
    expect(Post::sole()->content)->toBe('retry');
});

it('refuses PUT, DELETE and PATCH .../date on a group with a mix of released and unreleased segments', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [
                ['content' => 'segment 0'],
                ['content' => 'segment 1'],
                ['content' => 'segment 2'],
                ['content' => 'segment 3'],
                ['content' => 'segment 4'],
            ]],
        ],
    ]);
    $response->assertCreated();
    $group = PostGroup::findOrFail($response->json('id'));

    // Exactly the partial-failure shape the lock exists for: two of the
    // five segments genuinely went out (release_id set), the other
    // three did not.
    $posts = Post::query()->where('post_group_id', $group->id)->orderBy('order')->get();
    expect($posts)->toHaveCount(5);
    Post::query()->whereIn('id', $posts->take(2)->pluck('id'))
        ->update(['state' => PostState::PUBLISHED, 'release_id' => 'remote-partial']);

    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'replacement']]]],
    ])->assertStatus(409);

    $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => now()->addDay()->toIso8601String(),
    ])->assertStatus(409);

    $this->deleteJson("/api/posts/{$group->id}")->assertStatus(409);

    // Untouched throughout: none of the three refusals mutated anything.
    expect(Post::query()->where('post_group_id', $group->id)->count())->toBe(5);
    expect(PostGroup::query()->find($group->id))->not->toBeNull();
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

/*
 * Block 4: a PUT replaces the recurrence rule the same way it replaces
 * the chains — wholesale. That is not incidental: it is the ONLY way to
 * stop a series, and the editor uses it (send the post back with no
 * unit). There is deliberately no separate "stop repeating" endpoint.
 */
it('stops a series when the recurrence fields are omitted from the update', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = PostGroup::factory()->for($org)->create([
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDay(),
        'repeat_unit' => RepeatUnit::DAY,
        'repeat_interval' => 1,
        'repeat_until' => now()->addMonth(),
    ]);

    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'no longer repeating']]]],
    ])->assertOk();

    $group->refresh();
    expect($group->repeat_unit)->toBeNull()
        // Cleared together, so a later edit cannot resurrect a rule from
        // leftover halves.
        ->and($group->repeat_interval)->toBeNull()
        ->and($group->repeat_until)->toBeNull();
});

it('rewrites the recurrence rule of a pending occurrence', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = PostGroup::factory()->for($org)->create([
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDay(),
        'repeat_unit' => RepeatUnit::DAY,
        'repeat_interval' => 1,
        'repeat_until' => now()->addMonth(),
    ]);

    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'repeat_unit' => 'MONTH',
        'repeat_interval' => 1,
        'repeat_until' => now()->addMonths(6)->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'monthly now']]]],
    ])->assertOk();

    $group->refresh();
    expect($group->repeat_unit)->toBe(RepeatUnit::MONTH)
        ->and($group->repeat_interval)->toBe(1);
});
