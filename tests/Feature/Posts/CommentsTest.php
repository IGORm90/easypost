<?php

use App\Models\Comment;
use App\Models\Organization;
use App\Models\PostGroup;

/*
 * Task 22: GET|POST /api/posts/{group}/comments. Internal team
 * discussion on a post group — only a member of the group's own
 * organization may read or write it. A non-member (or a caller pointed
 * at a group id from another organization entirely) gets exactly the
 * same 404 as a group that doesn't exist at all, same pattern as every
 * other `{group}`-scoped action on PostController.
 */

it('lists comments for a group, newest last, with the author attached', function () {
    $org = Organization::factory()->create();
    $user = actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();
    $first = Comment::factory()->for($group, 'group')->for($user)->create(['content' => 'first', 'created_at' => now()->subMinute()]);
    $second = Comment::factory()->for($group, 'group')->for($user)->create(['content' => 'second', 'created_at' => now()]);

    $response = $this->getJson("/api/posts/{$group->id}/comments");

    $response->assertOk();
    $ids = collect((array) $response->json())->pluck('id');
    expect($ids->all())->toBe([$first->id, $second->id]);
    expect($response->json('0.content'))->toBe('first');
    expect($response->json('0.user.id'))->toBe($user->id);
    expect($response->json('0.user.name'))->toBe($user->name);
});

it('creates a comment as the authenticated member', function () {
    $org = Organization::factory()->create();
    $user = actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();

    $response = $this->postJson("/api/posts/{$group->id}/comments", ['content' => 'Looks good to me.']);

    $response->assertCreated();
    expect($response->json('content'))->toBe('Looks good to me.');
    expect($response->json('user.id'))->toBe($user->id);

    $comment = Comment::query()->findOrFail($response->json('id'));
    expect($comment->post_group_id)->toBe($group->id);
    expect($comment->user_id)->toBe($user->id);
});

it('rejects an empty comment body', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();

    $response = $this->postJson("/api/posts/{$group->id}/comments", ['content' => '']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['content']);
});

it('rejects a comment body over the length limit', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();

    $response = $this->postJson("/api/posts/{$group->id}/comments", ['content' => str_repeat('a', 5001)]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['content']);
});

it("404s listing another organization's group comments", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignGroup = PostGroup::factory()->for($other)->create();

    $this->getJson("/api/posts/{$foreignGroup->id}/comments")->assertNotFound();
});

it("404s posting a comment to another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignGroup = PostGroup::factory()->for($other)->create();

    $this->postJson("/api/posts/{$foreignGroup->id}/comments", ['content' => 'sneaky'])->assertNotFound();
});

it('401s for a caller with no session at all', function () {
    $group = PostGroup::factory()->create();

    $this->getJson("/api/posts/{$group->id}/comments")->assertUnauthorized();
});
