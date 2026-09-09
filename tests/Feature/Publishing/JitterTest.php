<?php

use App\Enums\PostState;
use App\Enums\RepeatUnit;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\PublishService;
use Illuminate\Support\Facades\Queue;

/*
 * Durable-scheduling rewrite, Task 6: publish-time jitter. A round
 * number like 12:00 is a magnet — a thousand tenants scheduling for the
 * same instant would otherwise all be claimed by the same
 * DueDispatchService tick and dispatched in the same burst, which is
 * exactly the kind of load a shared per-provider rate limit cannot
 * absorb. Spreading the actual publish_date by a few minutes, decided at
 * SCHEDULING time rather than left to the dispatcher, is the fix.
 *
 * beforeEach Queue::fake(): type=now creates dispatch for real on the
 * sync queue driver — see CreatePostTest's identical note.
 */
beforeEach(fn () => Queue::fake());

it('spreads a scheduled publish_date within the configured jitter window', function () {
    config(['publishing.jitter_seconds' => 120]);

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $requested = now()->addDay()->second(0);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => $requested->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();

    expect(abs($group->publish_date->diffInSeconds($requested, false)))->toBeLessThanOrEqual(120);
});

it('is deterministic per group: re-saving the same schedule does not walk the jittered time further', function () {
    config(['publishing.jitter_seconds' => 120]);

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $requested = now()->addDay()->second(0);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => $requested->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();
    $firstJitteredDate = $group->publish_date->copy();

    // Re-save with the SAME requested date and content — a no-op edit,
    // the kind autosave or a duplicate submit produces.
    $this->putJson("/api/posts/{$group->id}", [
        'type' => 'schedule',
        'publish_date' => $requested->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertOk();

    expect($group->fresh()->publish_date->isSameSecond($firstJitteredDate))->toBeTrue();
});

it('never jitters a type=now publish — the user is watching', function () {
    config(['publishing.jitter_seconds' => 120]);

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $before = now();

    $this->postJson('/api/posts', [
        'type' => 'now',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();

    expect($group->publish_date->diffInSeconds($before, true))->toBeLessThan(5)
        ->and($group->state)->toBe(PostState::QUEUE);
});

it('never jitters a draft — no schedule commitment exists yet to spread', function () {
    config(['publishing.jitter_seconds' => 120]);

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $requested = now()->addDay();

    $this->postJson('/api/posts', [
        'type' => 'draft',
        'publish_date' => $requested->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();

    expect($group->publish_date->isSameSecond($requested))->toBeTrue();
});

it('a jitter window of zero disables jitter entirely', function () {
    config(['publishing.jitter_seconds' => 0]);

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $requested = now()->addDay();

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => $requested->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();

    expect($group->publish_date->isSameSecond($requested))->toBeTrue();
});

it('spreads a recurring successor\'s publish_date within the same window', function () {
    config(['publishing.jitter_seconds' => 120]);

    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now(),
        'repeat_unit' => RepeatUnit::DAY,
        'repeat_interval' => 1,
        'repeat_until' => now()->addMonth(),
    ]);
    Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'daily',
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);

    app(PublishService::class)->publishGroup($group->id);

    $next = PostGroup::withoutGlobalScope('organization')->whereKeyNot($group->id)->sole();
    $expected = $group->fresh()->publish_date->copy()->addDay();

    expect(abs($next->publish_date->diffInSeconds($expected, false)))->toBeLessThanOrEqual(120);
});
