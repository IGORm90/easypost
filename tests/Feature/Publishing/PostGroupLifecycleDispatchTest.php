<?php

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Services\Posts\PostGroupLifecycle;
use App\Services\Posts\Receipt;
use App\Support\OrganizationContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * dispatch(Receipt): the one place a PublishPostJob is dispatched for a group
 * that has just entered QUEUE, and the one place that stamps the dispatch
 * bookkeeping for it (docs/plans/architecture-deepening.md, step 1.4(d)). The
 * caller calls it AFTER its own transaction has committed, never inside it.
 * HTTP-level behaviour is also pinned by CreatePostTest, QueueVersionTest and
 * RescheduleTest; this file proves it at the interface.
 */

beforeEach(function () {
    Queue::fake();
    OrganizationContext::set(Organization::factory()->create());
});

afterEach(fn () => OrganizationContext::clear());

function dispatchGroup(PostState $state, int $version = 4): PostGroup
{
    $org = OrganizationContext::get();
    assert($org !== null);

    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => $state,
        'queue_version' => $version,
        'dispatch_attempts' => 2,
        'last_dispatched_at' => null,
    ]);
}

it('dispatches the job for a QUEUE group, carrying the receipt\'s version, and stamps the bookkeeping', function () {
    $this->freezeTime();
    $group = dispatchGroup(PostState::QUEUE);

    app(PostGroupLifecycle::class)->dispatch(new Receipt($group, 4));

    Queue::assertPushed(PublishPostJob::class, 1);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id && $job->queueVersion === 4);

    $fresh = $group->fresh();
    expect($fresh->dispatch_attempts)->toBe(3)
        ->and($fresh->last_dispatched_at?->timestamp)->toBe(now()->timestamp);
});

it('does nothing for a group that is not in QUEUE', function (PostState $state) {
    $group = dispatchGroup($state);

    app(PostGroupLifecycle::class)->dispatch(new Receipt($group, 4));

    Queue::assertNothingPushed();

    $fresh = $group->fresh();
    expect($fresh->dispatch_attempts)->toBe(2)
        ->and($fresh->last_dispatched_at)->toBeNull();
})->with([
    'draft' => PostState::DRAFT,
    'scheduled' => PostState::SCHEDULED,
    'published' => PostState::PUBLISHED,
    'error' => PostState::ERROR,
]);

it('is deduped by the uniqueness lock when not forced, and gets past it when forced', function () {
    $group = dispatchGroup(PostState::QUEUE);
    $stale = new PublishPostJob($group->id, 4);
    $lock = Cache::lock(UniqueLock::getKey($stale), 3600);
    expect($lock->get())->toBeTrue();

    try {
        // Not forced: the same generation's lock is still held, so the dispatch is skipped.
        Log::shouldReceive('warning')->once()->with('unique_job.skipped', [
            'job' => PublishPostJob::class,
            'unique_id' => "{$group->id}:4",
        ]);
        app(PostGroupLifecycle::class)->dispatch(new Receipt($group, 4));
        Queue::assertNothingPushed();

        // Forced (a retry or an edit must always get a job): the lock is released first.
        app(PostGroupLifecycle::class)->dispatch(new Receipt($group, 4, forced: true));
        Queue::assertPushed(PublishPostJob::class, 1);
    } finally {
        $lock->release();
    }
});
