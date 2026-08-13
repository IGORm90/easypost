<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;

/*
 * Task 32: GET /api/posts/errors?from=&to= — failed publications for a
 * UI banner. Same tenant bar as the rest of the posts group
 * (auth:sanctum + organization), scoped by the model's normal
 * BelongsToOrganization global scope (a real request, unlike the
 * scanner's cross-tenant queries).
 */

it('lists failed publications in range for the current organization', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['name' => 'Acme Telegram']);

    $failed = PostGroup::factory()->for($org)->create([
        'state' => PostState::ERROR,
        'publish_date' => now()->subDay(),
        'error' => 'Publishing failed on the following channel(s): Acme Telegram.',
    ]);
    Post::factory()->for($failed, 'group')->create([
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => PostState::ERROR,
        'error' => 'The channel rejected the post.',
    ]);

    $response = $this->getJson('/api/posts/errors?'.http_build_query([
        'from' => now()->subWeek()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
    ]));

    $response->assertOk();
    $ids = collect((array) $response->json())->pluck('id');

    expect($ids)->toHaveCount(1)->and($ids->first())->toBe($failed->id);

    $entry = $response->json()[0];
    expect($entry['error'])->toContain('Acme Telegram')
        ->and($entry['channels'])->toHaveCount(1)
        ->and($entry['channels'][0]['integration']['id'])->toBe($integration->id);
});

it('excludes groups outside the requested range or not in ERROR', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $outOfRange = PostGroup::factory()->for($org)->create([
        'state' => PostState::ERROR,
        'publish_date' => now()->subMonth(),
    ]);
    $notFailed = PostGroup::factory()->for($org)->create([
        'state' => PostState::QUEUE,
        'publish_date' => now(),
    ]);

    $response = $this->getJson('/api/posts/errors?'.http_build_query([
        'from' => now()->subWeek()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
    ]));

    $response->assertOk();
    $ids = collect((array) $response->json())->pluck('id');

    expect($ids)->not->toContain($outOfRange->id)
        ->and($ids)->not->toContain($notFailed->id);
});

it('never returns another organization\'s failed publications', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $other = Organization::factory()->create();
    PostGroup::factory()->create([
        'organization_id' => $other->id,
        'state' => PostState::ERROR,
        'publish_date' => now(),
    ]);

    $response = $this->getJson('/api/posts/errors?'.http_build_query([
        'from' => now()->subWeek()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
    ]));

    $response->assertOk();
    expect($response->json())->toBe([]);
});

it('requires from and to', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->getJson('/api/posts/errors')->assertStatus(422);
});
