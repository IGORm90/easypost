<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Tag;

/*
 * Task 21: GET /api/calendar. Every filter (date range, tag, customer)
 * must be scoped to the caller's organization — see this file's
 * cross-tenant cases, which cover the date range AND the tag filter
 * separately, since a tag id from another organization must not widen
 * the result to "no filter at all".
 */

it('filters calendar entries by tag', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $tag = Tag::factory()->for($org)->create();

    $tagged = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($tagged, 'group')->create(['organization_id' => $org->id, 'integration_id' => $integration->id]);
    $tagged->tags()->attach($tag);

    $untagged = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($untagged, 'group')->create(['organization_id' => $org->id, 'integration_id' => $integration->id]);

    $response = $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
        'tags' => [$tag->id],
    ]));

    $response->assertOk();
    $ids = collect((array) $response->json())->pluck('id');

    expect($ids)->toHaveCount(1)->and($ids->first())->toBe($tagged->id);
});

it('filters calendar entries by customer', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $acme = Integration::factory()->for($org)->create(['provider_identifier' => 'fake', 'customer_name' => 'Acme']);
    $other = Integration::factory()->for($org)->create(['provider_identifier' => 'fake', 'customer_name' => 'Other']);

    $acmeGroup = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($acmeGroup, 'group')->create(['organization_id' => $org->id, 'integration_id' => $acme->id]);

    $otherGroup = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($otherGroup, 'group')->create(['organization_id' => $org->id, 'integration_id' => $other->id]);

    $response = $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
        'customer' => 'Acme',
    ]));

    $response->assertOk();
    $ids = collect((array) $response->json())->pluck('id');

    expect($ids)->toHaveCount(1)->and($ids->first())->toBe($acmeGroup->id);
});

it('does not leak posts from other organizations', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($group, 'group')->create(['organization_id' => $org->id, 'integration_id' => $integration->id]);

    $otherOrg = Organization::factory()->create();
    $otherIntegration = Integration::factory()->for($otherOrg)->create(['provider_identifier' => 'fake']);
    $otherGroup = PostGroup::factory()->for($otherOrg)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($otherGroup, 'group')->create(['organization_id' => $otherOrg->id, 'integration_id' => $otherIntegration->id]);

    $response = $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
    ]));

    $response->assertOk();
    $ids = collect((array) $response->json())->pluck('id');

    expect($ids)->toHaveCount(1)->and($ids->first())->toBe($group->id);
});

it('does not widen the calendar result when a tag id belongs to another organization', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($group, 'group')->create(['organization_id' => $org->id, 'integration_id' => $integration->id]);

    $otherOrg = Organization::factory()->create();
    $foreignTag = Tag::factory()->for($otherOrg)->create();

    $response = $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
        'tags' => [$foreignTag->id],
    ]));

    $response->assertOk();
    expect($response->json())->toBe([]);
});
