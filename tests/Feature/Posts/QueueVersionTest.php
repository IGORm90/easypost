<?php

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Support\Facades\Queue;

/*
 * Characterization of the queue_version rule, written BEFORE the PostGroup
 * lifecycle is moved into one module (docs/plans/architecture-deepening.md,
 * step 1.1). These tests pass on the current code and pin the exact
 * arithmetic the stale-job check depends on, which the neighbouring suites
 * only pin loosely (`toBeGreaterThan(...)`):
 *
 *   - a brand-new group starts at 1 only when it enters QUEUE;
 *   - every write that (re)enters the pipeline (SCHEDULED or QUEUE result)
 *     bumps by exactly 1, and clears notified_at in the same write;
 *   - a draft write bumps nothing;
 *   - rescheduling bumps ONLY when it un-claims a QUEUE group.
 *
 * queue_version is exposed by no resource, so it is observed two ways: the
 * version the dispatched PublishPostJob carries where a job is dispatched,
 * and the column itself where nothing is.
 */

beforeEach(fn () => Queue::fake());

/**
 * @return array{0: Organization, 1: Integration}
 */
function queueVersionSetup(): array
{
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    return [$org, $integration];
}

/**
 * @return array<string, mixed>
 */
function queueVersionPayload(Integration $integration, string $type): array
{
    return [
        'type' => $type,
        'publish_date' => $type === 'schedule' ? now()->addDay()->toIso8601String() : null,
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ];
}

it('starts a publish-now group at queue_version 1 and dispatches a job carrying exactly that', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'now'))->assertCreated();

    $group = PostGroup::sole();
    expect($group->queue_version)->toBe(1)
        ->and($group->state)->toBe(PostState::QUEUE);

    Queue::assertPushed(PublishPostJob::class, 1);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === 1);
});

it('leaves a scheduled group at queue_version 0 and dispatches nothing at creation', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'schedule'))->assertCreated();

    expect(PostGroup::sole()->queue_version)->toBe(0)
        ->and(PostGroup::sole()->state)->toBe(PostState::SCHEDULED);

    Queue::assertNothingPushed();
});

it('leaves a draft at queue_version 0', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'draft'))->assertCreated();

    expect(PostGroup::sole()->queue_version)->toBe(0);
});

it('bumps queue_version by exactly one when a scheduled group is edited and stays scheduled, and clears notified_at', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'schedule'))->assertCreated();
    $group = PostGroup::sole();
    $group->update(['queue_version' => 4, 'notified_at' => now()->subHour()]);

    $this->putJson("/api/posts/{$group->id}", queueVersionPayload($integration, 'schedule'))->assertOk();

    $group->refresh();
    expect($group->queue_version)->toBe(5)
        ->and($group->notified_at)->toBeNull()
        ->and($group->state)->toBe(PostState::SCHEDULED);

    Queue::assertNothingPushed();
});

it('does not bump queue_version, nor touch notified_at, when a draft is edited as a draft', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'draft'))->assertCreated();
    $group = PostGroup::sole();
    $notifiedAt = now()->subHour();
    $group->update(['queue_version' => 4, 'notified_at' => $notifiedAt]);

    $this->putJson("/api/posts/{$group->id}", queueVersionPayload($integration, 'draft'))->assertOk();

    $group->refresh();
    expect($group->queue_version)->toBe(4)
        ->and($group->notified_at)->not->toBeNull();
});

it('bumps queue_version by exactly one when a draft is published now, and the dispatched job carries the new version', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'draft'))->assertCreated();
    $group = PostGroup::sole();

    $this->putJson("/api/posts/{$group->id}", queueVersionPayload($integration, 'now'))->assertOk();

    $group->refresh();
    expect($group->queue_version)->toBe(1)
        ->and($group->state)->toBe(PostState::QUEUE);

    Queue::assertPushed(PublishPostJob::class, 1);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === 1);
});

it('does not bump queue_version when a scheduled group is rescheduled', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'schedule'))->assertCreated();
    $group = PostGroup::sole();
    $group->update(['queue_version' => 3]);

    $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => now()->addDays(2)->toIso8601String(),
    ])->assertOk();

    $group->refresh();
    expect($group->queue_version)->toBe(3)
        ->and($group->state)->toBe(PostState::SCHEDULED);
});

it('bumps queue_version by exactly one, and only once, when rescheduling un-claims a QUEUE group', function () {
    [, $integration] = queueVersionSetup();

    $this->postJson('/api/posts', queueVersionPayload($integration, 'now'))->assertCreated();
    $group = PostGroup::sole();
    expect($group->queue_version)->toBe(1);

    $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => now()->addHours(2)->toIso8601String(),
    ])->assertOk();

    $group->refresh();
    expect($group->queue_version)->toBe(2)
        ->and($group->state)->toBe(PostState::SCHEDULED);

    Queue::assertPushed(PublishPostJob::class, 1);
});

it('bumps queue_version by exactly one on retry, and the dispatched job carries the new version', function () {
    [$org, $integration] = queueVersionSetup();

    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::ERROR,
        'error' => 'the channel rejected the post',
        'queue_version' => 3,
    ]);
    Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'failed segment',
        'settings' => [],
        'state' => PostState::ERROR,
        'error' => 'auth token expired',
    ]);

    $this->postJson("/api/posts/{$group->id}/retry")->assertOk();

    expect($group->fresh()->queue_version)->toBe(4);

    Queue::assertPushed(PublishPostJob::class, 1);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === 4);
});
