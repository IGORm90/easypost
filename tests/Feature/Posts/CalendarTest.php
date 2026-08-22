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

/*
 * A DRAFT group's publish_date is optional at the request level, but
 * PostGroupService::resolvePublishDate() fills in now() when none is
 * given (Task 24 fix) — without that, a dateless draft would carry a
 * NULL publish_date, which whereBetween() never matches in ANY range,
 * making the draft permanently invisible on the only screen that lists
 * posts. These two cases cover both directions end to end, through the
 * real POST /api/posts endpoint rather than a factory, so they exercise
 * PostGroupService itself, not just the read side.
 */

it('a draft created with no publish_date lands on today and is returned by GET /api/calendar for a range covering today', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $create = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'dateless draft']]],
        ],
    ]);

    $create->assertCreated();
    $groupId = $create->json('id');

    $group = PostGroup::findOrFail($groupId);
    expect($group->state)->toBe(PostState::DRAFT)
        ->and($group->publish_date)->not->toBeNull()
        ->and($group->publish_date->isToday())->toBeTrue()
        ->and($group->publish_date->utcOffset())->toBe(0);

    $response = $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->startOfDay()->toIso8601String(),
        'to' => now()->endOfDay()->toIso8601String(),
    ]));

    $response->assertOk();
    $ids = collect((array) $response->json())->pluck('id');
    expect($ids)->toContain($groupId);
});

it('a draft created WITH an explicit publish_date keeps it, appearing only in a range covering that date, not today', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $future = now()->addWeek();

    $create = $this->postJson('/api/posts', [
        'type' => 'draft',
        'publish_date' => $future->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'dated draft']]],
        ],
    ]);

    $create->assertCreated();
    $groupId = $create->json('id');

    $group = PostGroup::findOrFail($groupId);
    expect($group->publish_date->isSameDay($future))->toBeTrue();

    // Present in a range covering the explicit future date...
    $futureRange = $this->getJson('/api/calendar?'.http_build_query([
        'from' => $future->copy()->subDay()->toIso8601String(),
        'to' => $future->copy()->addDay()->toIso8601String(),
    ]));
    $futureRange->assertOk();
    expect(collect((array) $futureRange->json())->pluck('id'))->toContain($groupId);

    // ...and absent from a range covering only today — the given date
    // was kept verbatim, not silently overwritten with now().
    $todayRange = $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->startOfDay()->toIso8601String(),
        'to' => now()->endOfDay()->toIso8601String(),
    ]));
    $todayRange->assertOk();
    expect(collect((array) $todayRange->json())->pluck('id'))->not->toContain($groupId);
});

/*
 * Task 1.1 (share button): the calendar card needs to show whether a
 * public link is already active without fetching the full group, so the
 * calendar entry carries `share_token` — the same field PostGroupResource
 * has always exposed. It is the organization's own data; the public
 * viewer route is what the token opens, and only members ever see this
 * response.
 */
it('exposes the share token on calendar entries', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $shared = PostGroup::factory()->for($org)->create([
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDay(),
        'share_token' => str_repeat('a', 64),
    ]);
    Post::factory()->for($shared, 'group')->create(['organization_id' => $org->id, 'integration_id' => $integration->id]);

    $private = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()->addDay()]);
    Post::factory()->for($private, 'group')->create(['organization_id' => $org->id, 'integration_id' => $integration->id]);

    $entries = collect((array) $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->subDay()->toIso8601String(),
        'to' => now()->addWeek()->toIso8601String(),
    ]))->assertOk()->json())->keyBy('id');

    expect($entries[$shared->id]['share_token'])->toBe(str_repeat('a', 64))
        ->and($entries[$private->id]['share_token'])->toBeNull();
});
