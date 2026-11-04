<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\PostGroup;
use Illuminate\Support\Facades\Queue;

/*
 * When a PUT is wrong in more than one way, which error does the caller get?
 * Request validation runs first, so an integration the caller may not use is a
 * 422 before the editability guard (409) or the target-state rule (422) is ever
 * consulted. Before step 4.0 of docs/plans/architecture-deepening.md it was
 * the other way round (409, then a 403 from the service, then 422), which is
 * what let a caller tell a foreign integration from a missing one.
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

it('answers 422 for a foreign integration before it looks at the group, whatever the group\'s state', function (PostState $state) {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = precedenceGroup($integration);
    $group->update(['state' => $state]);
    $foreign = Integration::factory()->for(Organization::factory()->create())->create(['provider_identifier' => 'fake']);

    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [['integration_id' => $foreign->id, 'value' => [['content' => 'A']]]],
    ])->assertStatus(422)->assertJsonValidationErrors(['posts.0.integration_id']);

    expect($group->fresh()->state)->toBe($state);
})->with([
    'published (would be 409)' => PostState::PUBLISHED,
    'scheduled (would be "cannot go back to draft")' => PostState::SCHEDULED,
]);
