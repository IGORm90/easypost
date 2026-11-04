<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\PostGroup;
use Illuminate\Support\Facades\Queue;

/*
 * When a PUT is wrong in more than one way, which error does the caller get?
 * Today: the editability guard first (409), then integration ownership (403),
 * then the target-state rule (422). The lifecycle refactor (step 1.4(c) of
 * docs/plans/architecture-deepening.md) moves this code; this file pins the
 * order so it cannot change by accident.
 */

beforeEach(fn () => Queue::fake());

function precedenceGroup(Integration $integration): PostGroup
{
    $response = test()->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'original']]]],
    ]);
    $response->assertCreated();

    return PostGroup::findOrFail($response->json('id'));
}

it('answers 409 before 403 when the group is already published and the integration is foreign', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = precedenceGroup($integration);
    $group->update(['state' => PostState::PUBLISHED]);
    $foreign = Integration::factory()->for(Organization::factory()->create())->create(['provider_identifier' => 'fake']);

    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $foreign->id, 'value' => [['content' => 'A']]]],
    ])->assertStatus(409);
});

it('answers 403 before 422 when the integration is foreign and the group cannot go back to draft', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = precedenceGroup($integration);
    $group->update(['state' => PostState::SCHEDULED]);
    $foreign = Integration::factory()->for(Organization::factory()->create())->create(['provider_identifier' => 'fake']);

    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $foreign->id, 'value' => [['content' => 'A']]]],
    ])->assertForbidden();

    expect($group->fresh()->state)->toBe(PostState::SCHEDULED);
});
