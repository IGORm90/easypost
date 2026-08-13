<?php

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Jobs\ScanMissedPostsJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Notifications\PostFailedNotification;
use App\Services\Posts\MissedPostsService;
use App\Support\OrganizationContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Task 32: MissedPostsService / ScanMissedPostsJob — the safety net for
 * the two holes PublishService::lockGroupIfQueued()'s docblock
 * documents: a crash/outage between commit and dispatch, and a
 * ShouldBeUnique swallow outside the already-hardened reschedule paths.
 * Both leave a group at QUEUE, publish_date in the past, no live job.
 *
 * Runs with NO OrganizationContext set throughout, matching production
 * (a scheduled job — see AppServiceProvider's queue-event listeners) —
 * every fixture is built with an explicit organization_id.
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

function overdueGroup(Organization $org, ?DateTimeInterface $publishDate = null): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => $publishDate ?? now()->subMinutes(20),
    ]);
}

// --- Redispatch: orphaned vs. fresh ----------------------------------------

it('redispatches a group stuck at QUEUE well past its publish date', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    app(MissedPostsService::class)->scan();

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id
        && $job->queueVersion === $group->fresh()->queue_version);
});

it('logs a warning when it redispatches an orphaned group', function () {
    // Log::shouldReceive(), not Log::spy()+shouldHaveReceived() — see
    // ImpersonationTest's identical note: the latter's assertion method
    // only exists on the Mockery spy instance Facade::__callStatic()
    // forwards to at runtime, invisible to Larastan's static analysis.
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'missed_posts.redispatched'
            && $context['post_group_id'] === $group->id);

    app(MissedPostsService::class)->scan();
});

it('does not touch a group whose publish date is not overdue yet', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    // Within the OVERDUE_MINUTES (15) grace window.
    overdueGroup($org, now()->subMinutes(5));

    app(MissedPostsService::class)->scan();

    Queue::assertNotPushed(PublishPostJob::class);
});

it('does not touch a group that is not QUEUE at all', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::PUBLISHED,
        'publish_date' => now()->subMinutes(20),
    ]);

    app(MissedPostsService::class)->scan();

    Queue::assertNotPushed(PublishPostJob::class);
});

// --- Mid-retry groups must be left alone -----------------------------------

it('does not touch a group that is legitimately mid-retry, and logs nothing for it', function () {
    // PublishPostJob's own backoff schedule ([60, 300, 900] across 4
    // tries) can keep a group at QUEUE, past its publish_date, for
    // roughly 21 minutes while genuinely retrying — well past this
    // scanner's own 15-minute OVERDUE_MINUTES threshold. The mechanism
    // that must save it is the ShouldBeUnique lock, not the date
    // threshold: this test holds the EXACT lock a real in-flight
    // PublishPostJob dispatch would hold (same pattern as
    // TokenRefreshTest's "never even queues a second refresh job..."
    // case) and proves the scanner backs off completely — no dispatch,
    // no warning logged — rather than merely "no duplicate eventually".
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    Log::shouldReceive('warning')->never();

    $inFlight = new PublishPostJob($group->id, $group->queue_version);
    $lock = Cache::lock(UniqueLock::getKey($inFlight), 3600);
    expect($lock->get())->toBeTrue();

    try {
        app(MissedPostsService::class)->scan();

        Queue::assertNotPushed(PublishPostJob::class);
    } finally {
        $lock->release();
    }
});

// --- Never dispatches alongside a live job ---------------------------------

it('never lets a redispatch reach the queue while a live job still holds the unique lock', function () {
    // The real (non-faked) queue path: proves the actual
    // PublishPostJob::dispatch() call inside redispatchIfOrphaned() is
    // itself gated by the SAME ShouldBeUnique lock, as a second,
    // end-to-end layer on top of the lock-probe test above.
    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(20));

    $inFlight = new PublishPostJob($group->id, $group->queue_version);
    $lock = Cache::lock(UniqueLock::getKey($inFlight), 3600);
    expect($lock->get())->toBeTrue();

    try {
        app(MissedPostsService::class)->scan();

        // The group must remain untouched — no second job means
        // publishGroup() never ran again from this scan.
        expect($group->fresh()->state)->toBe(PostState::QUEUE);
    } finally {
        $lock->release();
    }
});

// --- Cross-tenant enumeration -----------------------------------------------

it('enumerates overdue groups across every tenant with no organization context set', function () {
    Queue::fake();
    OrganizationContext::clear();
    expect(OrganizationContext::get())->toBeNull();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Created with an explicit organization_id (not through the
    // ambient/scoped ORM) precisely because no context is set here —
    // BelongsToOrganization's creating() hook would otherwise leave
    // organization_id null.
    $groupA = PostGroup::factory()->create([
        'organization_id' => $orgA->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->subMinutes(30),
    ]);
    $groupB = PostGroup::factory()->create([
        'organization_id' => $orgB->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->subMinutes(45),
    ]);

    expect(OrganizationContext::get())->toBeNull();

    app(MissedPostsService::class)->scan();

    expect(OrganizationContext::get())->toBeNull();

    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $groupA->id);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $groupB->id);
});

// --- Notification: sent once, group.error made useful ----------------------

it('notifies the author and records a customer-facing error once a group settles to ERROR', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $owner = $org->owner;
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => $owner->id,
        'state' => PostState::ERROR,
        'error' => null,
    ]);
    $integration = Integration::factory()->for($org)->create(['name' => 'My Telegram Channel']);
    Post::factory()->for($group, 'group')->create([
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => PostState::ERROR,
    ]);

    app(MissedPostsService::class)->scan();

    $fresh = $group->fresh();
    expect($fresh->notified_at)->not->toBeNull()
        ->and($fresh->error)->not->toBeNull()
        ->and($fresh->error)->toContain('My Telegram Channel')
        // Never the raw internal reason a fresh, unlabeled ERROR group
        // has none of anyway — this asserts the summary is genuinely
        // built from the customer-visible channel name, not left empty
        // or generic when better information exists.
        ->and($fresh->error)->not->toContain('Exception');

    Notification::assertSentTo($owner, PostFailedNotification::class);
});

it('does not re-notify the author once a group has already been notified', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $owner = $org->owner;
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => $owner->id,
        'state' => PostState::ERROR,
        'error' => null,
    ]);

    app(MissedPostsService::class)->scan();
    Notification::assertSentToTimes($owner, PostFailedNotification::class, 1);

    app(MissedPostsService::class)->scan();
    Notification::assertSentToTimes($owner, PostFailedNotification::class, 1);
});

it('does not notify or error a group that is still QUEUE', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDay(),
    ]);

    app(MissedPostsService::class)->scan();

    Notification::assertNothingSent();
});

it('does not blow up notifying a group whose author was removed', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => null,
        'state' => PostState::ERROR,
        'error' => null,
    ]);

    app(MissedPostsService::class)->scan();

    expect($group->fresh()->notified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

// --- The actual scheduled entry point --------------------------------------

it('dispatches the scan through ScanMissedPostsJob exactly as the scheduler runs it', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $group = overdueGroup($org, now()->subMinutes(30));

    OrganizationContext::clear();
    expect(OrganizationContext::get())->toBeNull();

    (new ScanMissedPostsJob)->handle(app(MissedPostsService::class));

    expect(OrganizationContext::get())->toBeNull();
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id);
});
