<?php

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Services\Posts\PostGroupService;
use App\Services\Posts\PublishService;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Support\OrganizationContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ShortResultProvider;

/*
 * Task 30: PublishPostJob / PublishService — the actual act of
 * publishing a post group. This is the highest-risk task in the
 * project: the single worst failure this product can produce is
 * publishing a customer's post twice. Every test below drives
 * PublishService directly (never through a real dispatch()) or
 * constructs PublishPostJob and calls handle()/failed() on it directly
 * — release()/attempts() are exercised via the framework's own
 * InteractsWithQueue::withFakeQueueInteractions()/assertReleased(),
 * never a Mockery partial mock, so PublishPostJob can stay `final`.
 *
 * Runs with NO OrganizationContext set throughout, matching how this
 * code actually executes in production (a queued job, with
 * ClearOrganizationContext having already cleared it) — every fixture
 * below is built with an explicit organization_id rather than relying
 * on ambient context.
 */

beforeEach(function () {
    OrganizationContext::clear();
});

afterEach(function () {
    OrganizationContext::clear();
});

/*
 * `publishableChannel()` and `queuedGroup()` used to be declared here;
 * they now live in `tests/Pest.php` (which is loaded for every run,
 * unlike a test file, which is only preloaded on a full-suite run) —
 * see the note there.
 */

it('publishes every channel in the group and marks it published', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    app(PublishService::class)->publishGroup($group->id);

    $postA = $postsA[0]->fresh();
    $postB = $postsB[0]->fresh();

    expect($postA->state)->toBe(PostState::PUBLISHED)
        ->and($postA->release_id)->not->toBeNull()
        ->and($postA->release_url)->not->toBeNull()
        ->and($postA->published_at)->not->toBeNull()
        ->and($postB->state)->toBe(PostState::PUBLISHED)
        ->and($postB->release_id)->not->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    expect($provider->publishedChains())->toHaveCount(2);
});

it('publishes a multi-segment thread in one call, mapping each result onto the correct row in order', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['root', 'reply one', 'reply two']);

    app(PublishService::class)->publishGroup($group->id);

    $root = $posts[0]->fresh();
    $replyOne = $posts[1]->fresh();
    $replyTwo = $posts[2]->fresh();

    expect($root->state)->toBe(PostState::PUBLISHED)
        ->and($replyOne->state)->toBe(PostState::PUBLISHED)
        ->and($replyTwo->state)->toBe(PostState::PUBLISHED)
        ->and($root->release_id)->not->toBeNull()
        ->and($replyOne->release_id)->not->toBeNull()
        ->and($replyTwo->release_id)->not->toBeNull()
        // Every row got its OWN result, not the same one three times, and
        // the mapping is index-correct (review finding 5's central
        // concern: a mis-mapping here writes the wrong release_id onto a
        // segment).
        ->and(array_unique([$root->release_id, $replyOne->release_id, $replyTwo->release_id]))->toHaveCount(3)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($provider->publishedChains()[0])->toHaveCount(3)
        ->and($provider->publishedChains()[0][0]->content)->toBe('root')
        ->and($provider->publishedChains()[0][1]->content)->toBe('reply one')
        ->and($provider->publishedChains()[0][2]->content)->toBe('reply two');

    // The mapping itself, not just distinctness (review finding 5,
    // round 2): FakeProvider mints random ids, so two segments merely
    // being different from each other does not prove row i got RESULT
    // i — reversing $results[$index] <-> $pending[$index] would still
    // leave every id distinct. publishedSegments() records ids in the
    // exact order post() produced them, so row i's release_id must equal
    // publishedSegments()[i]'s.
    $segments = $provider->publishedSegments();
    expect($segments)->toHaveCount(3)
        ->and($segments[0]['releaseId'])->toBe($root->release_id)
        ->and($segments[1]['releaseId'])->toBe($replyOne->release_id)
        ->and($segments[2]['releaseId'])->toBe($replyTwo->release_id);
});

it('resumes a two-segment pending remainder behind an already-published prefix, anchoring the first pending segment', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['root', 'reply one', 'reply two']);

    // Segment 0 already went out in an earlier attempt.
    $posts[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'previously-released-root',
        'release_url' => 'https://fake.test/previously',
        'published_at' => now()->subMinute(),
    ]);

    app(PublishService::class)->publishGroup($group->id);

    $replyOne = $posts[1]->fresh();
    $replyTwo = $posts[2]->fresh();

    expect($replyOne->state)->toBe(PostState::PUBLISHED)
        ->and($replyTwo->state)->toBe(PostState::PUBLISHED)
        ->and($replyOne->release_id)->not->toBeNull()
        ->and($replyTwo->release_id)->not->toBeNull()
        ->and($replyOne->release_id)->not->toBe($replyTwo->release_id)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);

    // Only the two pending segments were sent, and the first of them
    // carried the anchor to the already-published root.
    $sentChain = $provider->publishedChains()[0];
    expect($sentChain)->toHaveCount(2)
        ->and($sentChain[0]->content)->toBe('reply one')
        ->and($sentChain[0]->replyToReleaseId)->toBe('previously-released-root')
        ->and($sentChain[1]->content)->toBe('reply two')
        ->and($sentChain[1]->replyToReleaseId)->toBeNull();

    // Mapping, not just distinctness (review finding 5, round 2) — see
    // the identical note in the three-segment test above. Only the two
    // NEW segments were ever sent through post(), so publishedSegments()
    // has exactly two entries here, in order.
    $segments = $provider->publishedSegments();
    expect($segments)->toHaveCount(2)
        ->and($segments[0]['releaseId'])->toBe($replyOne->release_id)
        ->and($segments[1]['releaseId'])->toBe($replyTwo->release_id);
});

it('is idempotent: a second run does not publish twice', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::PUBLISHED,
    ]);
    [, $posts] = publishableChannel($org, $group, ['already out']);
    $posts[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'already-released-id',
        'release_url' => 'https://fake.test/already',
        'published_at' => now(),
    ]);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($posts[0]->fresh()->release_id)->toBe('already-released-id')
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('skips already published posts on retry, at both channel and segment level', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);

    // Channel A: simulates a prior successful attempt already committed.
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    $postsA[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'previously-released-id',
        'release_url' => 'https://fake.test/previously',
        'published_at' => now()->subMinute(),
    ]);

    // Channel B: still pending.
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($postsA[0]->fresh()->release_id)->toBe('previously-released-id')
        ->and($postsB[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($postsB[0]->fresh()->release_id)->not->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('never re-locks or republishes a segment that is ERROR with no release_id', function () {
    // Review, round 4: the re-lock's WHERE must filter on state as well
    // as release_id. A segment can be ERROR with release_id still null —
    // e.g. a concurrent abandonGroup() run (PublishPostJob::failed())
    // settling this channel between publishChannel()'s unlocked
    // pre-check and this transaction acquiring the lock — and
    // release_id IS NULL alone would still pick it up, call the
    // provider, and then fail markPublished()'s ERROR -> PUBLISHED
    // transition INSIDE the transaction, AFTER the network call already
    // went out. The state = QUEUE filter is what makes this method skip
    // such a row instead, with no provider call at all.
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['channel A']);
    $posts[0]->update(['state' => PostState::ERROR, 'error' => 'settled by a concurrent run']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($posts[0]->fresh()->state)->toBe(PostState::ERROR)
        ->and($posts[0]->fresh()->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('marks only the failing channel as errored, while the other still publishes', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    fakeProvider()->failNextPostPermanently('rejected by the network');

    app(PublishService::class)->publishGroup($group->id);

    $postA = $postsA[0]->fresh();
    $postB = $postsB[0]->fresh();

    $published = $postA->state === PostState::PUBLISHED ? $postA : $postB;
    $errored = $postA->state === PostState::ERROR ? $postA : $postB;

    expect($postA->state)->not->toBe($postB->state)
        ->and($published->state)->toBe(PostState::PUBLISHED)
        ->and($published->release_id)->not->toBeNull()
        ->and($errored->state)->toBe(PostState::ERROR)
        ->and($errored->error)->toBe('rejected by the network')
        ->and($errored->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('releases the job with backoff on a transient error, leaving the group QUEUE', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostTransiently('network blip');

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->withFakeQueueInteractions();

    $job->handle(app(PublishService::class));

    $job->assertReleased(60);

    expect($group->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->release_id)->toBeNull();
});

it('honours the provider retryAfterSeconds on a rate limit when it is under the schedule ceiling', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    // 45s is under backoff[0] = 60s (this job's first-attempt ceiling),
    // so this exercises "provider asked for less than the cap" and must
    // be honoured exactly.
    fakeProvider()->failNextPostWithRateLimit(45, 'slow down');

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->withFakeQueueInteractions();

    $job->handle(app(PublishService::class));

    $job->assertReleased(45);

    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('caps a rate-limit release at this attempt\'s own backoff position, never the provider\'s uncapped request', function () {
    // Review finding 4: an uncapped rate-limit delay can outlive
    // $uniqueFor's fixed 3600s. A provider asking to wait far longer
    // than any schedule position allows (Telegram's own retry_after
    // routinely exceeds an hour under a broadcast-wide limit) must be
    // capped at THIS attempt's own backoff() position, exactly like
    // RefreshIntegrationTokenJob caps ProviderRateLimitException.
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostWithRateLimit(999_999, 'slow down for a very long time');

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->withFakeQueueInteractions();
    // Simulate the second attempt: backoff[1] = 300 is the ceiling here,
    // not backoff[0] = 60. $job->job is typed as the bare
    // Illuminate\Contracts\Queue\Job interface (no $attempts property);
    // withFakeQueueInteractions() always sets it to a concrete FakeJob,
    // which does have one.
    /** @var FakeJob $fakeJob */
    $fakeJob = $job->job;
    $fakeJob->attempts = 2;

    $job->handle(app(PublishService::class));

    $job->assertReleased(300);
});

it('treats a 429 carrying no Retry-After (retryAfterSeconds = 0) as "no specific delay", not as "retry immediately"', function () {
    // Final-review Important 4. ProviderRateLimitException::$retryAfterSeconds
    // is a non-nullable int that EVERY provider defaults to 0 when the
    // header or field is absent — which Slack, Mastodon and Bluesky 429s
    // frequently are. handle() compared it against null, and 0 is not
    // null, so min(0, 60) = 0 -> release(0): attempts 2, 3 and 4 were
    // consumed within milliseconds of the first 429, hammering the
    // rate-limited API three more times on the way, and then failed() ->
    // abandonGroup() marked every segment ERROR. A transient rate limit
    // became a permanent, customer-visible failure. 0 must mean
    // "unspecified", i.e. this attempt's own backoff position.
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostWithRateLimit(0, 'slow down, no Retry-After header');

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->withFakeQueueInteractions();

    $job->handle(app(PublishService::class));

    $job->assertReleased(60);

    expect($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('lets a sibling channel\'s real rate-limit delay win over another channel\'s unspecified one', function () {
    // The same normalisation, one level down: publishGroup() takes the
    // MINIMUM retryAfterSeconds across channels, so an unspecified 0 from
    // one channel used to swallow a real 45s request from another. Only
    // publishChannel() knows a 0 means "the provider said nothing", so
    // that is where it becomes null.
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);
    publishableChannel($org, $group, ['channel B']);

    fakeProvider()
        ->onPostContaining('channel A', new ProviderRateLimitException('no header', 0))
        ->onPostContaining('channel B', new ProviderRateLimitException('slow down', 45));

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->withFakeQueueInteractions();

    $job->handle(app(PublishService::class));

    $job->assertReleased(45);
});

it('flags the integration for reconnect on auth error and errors the post', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [$integration, $posts] = publishableChannel($org, $group, ['channel A']);

    fakeProvider()->failNextPostWithAuthError('token revoked');

    app(PublishService::class)->publishGroup($group->id);

    expect($integration->fresh()->refresh_needed)->toBeTrue()
        ->and($posts[0]->fresh()->state)->toBe(PostState::ERROR)
        ->and($posts[0]->fresh()->error)->toBe('token revoked')
        ->and($posts[0]->fresh()->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('does nothing when the group is not in the QUEUE state', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::DRAFT,
    ]);
    publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($group->fresh()->state)->toBe(PostState::DRAFT);
});

// --- Stale schedule / rescheduling (review finding 1) ---------------------

it('does not publish a group whose publish_date is still materially in the future', function () {
    // Simulates a stale job: dispatched for an old publish_date that has
    // since been moved further out (PostGroupService::updateDate()), but
    // this particular job instance still reflects the OLD schedule.
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addHours(8));
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);
});

it('does publish a group whose publish_date has just passed, within the grace window', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->subSecond());
    publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('redispatches PublishPostJob when the calendar moves publish_date, even while an earlier dispatch still holds the unique lock', function () {
    // Review finding 1, round 2: PublishPostJob's ShouldBeUnique lock is
    // a real Cache lock, acquired at dispatch() time with a fixed 3600s
    // TTL independent of ->delay() and NOT touched by Queue::fake() —
    // Illuminate\Foundation\Bus\PendingDispatch::shouldDispatch() checks
    // it via the real cache before Queue::fake() ever sees the push.
    // Without updateDate()'s forceRedispatch fix, a lock already held
    // for this group (simulating an earlier drag's still-pending
    // dispatch, or create()'s own dispatch moments earlier — a
    // multi-drag calendar session, not an edge case) would silently
    // swallow this one, per PendingDispatch::shouldDispatch() dispatching
    // UniqueJobSkipped and returning false instead of pushing. Acquiring
    // the lock manually first, exactly like
    // TokenRefreshTest's equivalent ShouldBeUnique coverage, proves the
    // fix directly rather than relying on real timing.
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = queuedGroup($org, now()->addHours(2));
    publishableChannel($org, $group, ['channel A']);

    $inFlight = new PublishPostJob($group->id, $group->queue_version);
    $lock = Cache::lock(UniqueLock::getKey($inFlight), $inFlight->uniqueFor);
    expect($lock->get())->toBeTrue();

    $newDate = now()->addHours(10);

    $this->patchJson("/api/posts/{$group->id}/date", [
        'publish_date' => $newDate->toIso8601String(),
    ])->assertOk();

    Queue::assertPushed(PublishPostJob::class, 1);
    Queue::assertPushed(fn (PublishPostJob $job): bool => $job->postGroupId === $group->id);
});

it('redispatches successfully across two consecutive reschedules of the same group (a multi-drag session)', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    // PostGroupService::updateDate() is HTTP-facing service code (unlike
    // the rest of this file) and legitimately requires OrganizationContext
    // for its now-locked, org-scoped read of the group row — set it by
    // hand since this test drives the service directly rather than
    // through HTTP.
    OrganizationContext::set($org);
    $group = queuedGroup($org, now()->addHours(2));
    publishableChannel($org, $group, ['channel A']);

    app(PostGroupService::class)->updateDate($group, now()->addHours(5));
    app(PostGroupService::class)->updateDate($group, now()->addHours(10));

    // Both drags reached the queue — never just the first, which is
    // exactly the bug: pre-fix, the second (and every subsequent) drag
    // within the same hour would have been silently dropped.
    Queue::assertPushed(PublishPostJob::class, 2);
});

it('logs when a ShouldBeUnique dispatch is silently skipped', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);

    $job = new PublishPostJob($group->id, $group->queue_version);
    $lock = Cache::lock(UniqueLock::getKey($job), $job->uniqueFor);
    expect($lock->get())->toBeTrue();

    try {
        Log::shouldReceive('warning')
            ->once()
            ->with('unique_job.skipped', [
                'job' => PublishPostJob::class,
                'unique_id' => $group->id,
            ]);

        PublishPostJob::dispatch($group->id, $group->queue_version);
    } finally {
        $lock->release();
    }
});

// --- Retries exhausted (review finding 3) ---------------------------------

it('errors every still-pending post and finalizes the group when the job fails for good', function () {
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $postsA] = publishableChannel($org, $group, ['channel A']);
    [, $postsB] = publishableChannel($org, $group, ['channel B']);

    // Channel A already published in an earlier attempt; channel B never
    // got a chance before retries ran out.
    $postsA[0]->update([
        'state' => PostState::PUBLISHED,
        'release_id' => 'already-released',
        'release_url' => 'https://fake.test/already',
        'published_at' => now()->subMinute(),
    ]);

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->failed(new MaxAttemptsExceededException('PublishPostJob has been attempted too many times.'));

    expect($postsA[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($postsA[0]->fresh()->release_id)->toBe('already-released')
        ->and($postsB[0]->fresh()->state)->toBe(PostState::ERROR)
        ->and($postsB[0]->fresh()->error)->not->toBeNull()
        ->and($postsB[0]->fresh()->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::ERROR);
});

it('failed() is a no-op once the group already resolved to PUBLISHED', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    app(PublishService::class)->publishGroup($group->id);
    expect($group->fresh()->state)->toBe(PostState::PUBLISHED);

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->failed(new MaxAttemptsExceededException('too many attempts'));

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($posts[0]->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('failed() does not error a group that has since been rescheduled further into the future', function () {
    // Review finding 2, second defect: a group dragged into the future
    // while a now-superseded job for its OLD schedule is still mid-retry
    // must not be marked ERROR just because that dead job's retries ran
    // out — it isn't due yet, and the redispatched job for the NEW date
    // (see the redispatch tests above) is what should get to publish it.
    // Without abandonGroup()'s due-date grace, this would wrongly settle
    // the group to ERROR, and the redispatched job would then find ERROR
    // (not QUEUE) and silently no-op — the post never publishes at all.
    $org = Organization::factory()->create();
    $group = queuedGroup($org, now()->addHours(6));
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->failed(new MaxAttemptsExceededException('too many attempts'));

    expect($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->error)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);
});

// --- Partial thread failure / resumption --------------------------------

it('persists the published prefix of a partial thread failure and resumes with the anchor, never duplicating the root', function () {
    $provider = fakeProvider();

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['root segment', 'reply segment']);

    // First segment "goes out" for real before the thread fails.
    $provider->failNextPostWithPartialFailure(1, 'reply failed');

    $threw = null;
    try {
        app(PublishService::class)->publishGroup($group->id);
    } catch (PublishRetryNeededException $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull();

    $root = $posts[0]->fresh();
    $reply = $posts[1]->fresh();

    expect($root->state)->toBe(PostState::PUBLISHED)
        ->and($root->release_id)->not->toBeNull()
        ->and($reply->state)->toBe(PostState::QUEUE)
        ->and($reply->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);

    $rootReleaseId = $root->release_id;

    // Retry: the second call must attach the reply to the already-live
    // root, never publish a fresh, detached root.
    app(PublishService::class)->publishGroup($group->id);

    expect($reply->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($reply->fresh()->release_id)->not->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($provider->rootReleaseIds())->toBe([$rootReleaseId]);
});

// --- Provider contract violation (Minor 6) --------------------------------

it('never throws on a provider returning fewer results than the chain, and retries the unresolved tail instead', function () {
    // ShortResultProvider always returns one fewer result than the chain
    // it was given, WITHOUT throwing — the exact contract violation
    // publishChannel()'s array_key_exists() guard exists for. Throwing
    // here instead would unwind the transaction and discard the
    // release_id already resolved for the segment that DID come back,
    // even though it is already live on the network — turning a
    // provider bug into a duplicate-publish setup on the very next run.
    config()->set('social.providers.short_result', ShortResultProvider::class);

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'short_result',
    ]);
    $root = Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'parent_post_id' => null,
        'order' => 0,
        'content' => 'root',
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);
    $reply = Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'parent_post_id' => $root->id,
        'order' => 1,
        'content' => 'reply',
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);

    $threw = null;
    try {
        app(PublishService::class)->publishGroup($group->id);
    } catch (PublishRetryNeededException $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull()
        ->and($root->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($root->fresh()->release_id)->not->toBeNull()
        ->and($reply->fresh()->state)->toBe(PostState::QUEUE)
        ->and($reply->fresh()->release_id)->toBeNull()
        ->and($group->fresh()->state)->toBe(PostState::QUEUE);
});

// --- Wiring: PostGroupService dispatches this job ------------------------

it('dispatches PublishPostJob, delayed to publish_date, when a group is created already QUEUE', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $publishDate = now()->addHour();

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => $publishDate->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();

    Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($group): bool {
        return $job->postGroupId === $group->id;
    });
});

it('does not dispatch anything for a draft group', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'hello']]],
        ],
    ])->assertCreated();

    Queue::assertNotPushed(PublishPostJob::class);
});
