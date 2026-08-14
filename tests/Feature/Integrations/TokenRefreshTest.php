<?php

use App\Jobs\RefreshIntegrationTokenJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Notifications\IntegrationBrokenNotification;
use App\Services\Integrations\TokenRefreshService;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Support\OrganizationContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/*
 * Task 16: the hourly OAuth-token-refresh job and the "broken channel"
 * notification path. Everything here runs against the 'fake' provider
 * (Tests\Support\FakeProvider, wired in Tests\TestCase::setUp()), now
 * extended with SupportsRefresh, plus the real TelegramProvider — the one
 * MVP provider that deliberately does NOT implement SupportsRefresh — to
 * prove the skip rule against a real class, not a test double standing in
 * for "doesn't support refresh".
 */

// fakeProvider() is a shared global test helper defined in
// tests/Unit/Social/FakeProviderTest.php — reused here rather than
// redeclared.

afterEach(function () {
    OrganizationContext::clear();
});

it('refreshes an expiring token', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token' => 'old-token',
        'token_expires_at' => now()->addMinutes(30),
        'refresh_needed' => true,
    ]);

    app(TokenRefreshService::class)->refresh($integration);

    $fresh = $integration->fresh();
    expect($fresh->token)->not->toBe('old-token')
        ->and($fresh->token)->toStartWith('fake-refreshed-token-')
        ->and($fresh->refresh_needed)->toBeFalse()
        ->and($fresh->disabled)->toBeFalse();
});

it('marks the integration as broken when refresh fails with auth error', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $owner = $org->owner;
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    fakeProvider()->failNextRefreshWith(new ProviderAuthException('refresh token revoked'));

    app(TokenRefreshService::class)->refresh($integration);

    expect($integration->fresh()->refresh_needed)->toBeTrue()
        ->and($integration->fresh()->disabled)->toBeTrue();

    Notification::assertSentTo($owner, IntegrationBrokenNotification::class);
});

it('skips providers that do not support refresh', function () {
    $org = Organization::factory()->create();
    Integration::factory()->for($org)->create([
        'provider_identifier' => 'telegram',
        'token_expires_at' => now()->addMinutes(30),
    ]);
    $refreshable = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    $due = app(TokenRefreshService::class)->dueForRefresh(now());

    expect($due->pluck('id')->all())->toBe([$refreshable->id]);
});

// --- Cross-tenant enumeration -------------------------------------------

it('enumerates due integrations across every tenant with no organization context set', function () {
    OrganizationContext::clear();
    expect(OrganizationContext::get())->toBeNull();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Created with an explicit organization_id (not through the
    // ambient/scoped ORM) precisely because no context is set here —
    // BelongsToOrganization's creating() hook would otherwise leave
    // organization_id null.
    $dueA = Integration::factory()->create([
        'organization_id' => $orgA->id,
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);
    $dueB = Integration::factory()->create([
        'organization_id' => $orgB->id,
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(90),
    ]);
    $notYetDue = Integration::factory()->create([
        'organization_id' => $orgA->id,
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addHours(5),
    ]);

    expect(OrganizationContext::get())->toBeNull();

    $due = app(TokenRefreshService::class)->dueForRefresh(now());

    expect($due->pluck('id')->sort()->values()->all())
        ->toBe(collect([$dueA->id, $dueB->id])->sort()->values()->all())
        ->and($due->pluck('id'))->not->toContain($notYetDue->id);
});

it('never enumerates a disabled or soft-deleted integration', function () {
    $org = Organization::factory()->create();
    $disabled = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
        'disabled' => true,
    ]);
    $trashed = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);
    $trashed->delete();

    $due = app(TokenRefreshService::class)->dueForRefresh(now());

    expect($due->pluck('id'))->not->toContain($disabled->id)
        ->and($due->pluck('id'))->not->toContain($trashed->id);
});

// --- Transient failures must not break the channel -----------------------

it('does not mark the channel broken on a transient provider error, and retries the job', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token' => 'old-token',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    fakeProvider()->failNextRefreshWith(new ProviderTransientException('network blip'));

    $job = new RefreshIntegrationTokenJob($integration);

    expect(fn () => $job->handle(app(TokenRefreshService::class)))
        ->toThrow(ProviderTransientException::class);

    $fresh = $integration->fresh();
    expect($fresh->token)->toBe('old-token')
        ->and($fresh->refresh_needed)->toBeFalse()
        ->and($fresh->disabled)->toBeFalse();

    Notification::assertNothingSent();
});

it('releases the job instead of breaking the channel on a rate limit, honouring retry-after', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token' => 'old-token',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    // 20s is under backoff()[0] (this job's first-attempt cap, 30s — see
    // the position-aware cap in handle()), so this exercises "provider
    // asked for less than the cap" and must be honoured exactly.
    fakeProvider()->failNextRefreshWith(new ProviderRateLimitException('slow down', 20));

    /** @var RefreshIntegrationTokenJob&MockInterface $job */
    $job = Mockery::mock(RefreshIntegrationTokenJob::class, [$integration])->makePartial();
    $job->shouldReceive('release')->once()->with(20);

    $job->handle(app(TokenRefreshService::class));

    $fresh = $integration->fresh();
    expect($fresh->token)->toBe('old-token')
        ->and($fresh->disabled)->toBeFalse();

    Notification::assertNothingSent();
});

it('caps a rate-limit release delay at THIS attempt\'s own schedule position, not the schedule\'s overall maximum', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token' => 'old-token',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    // A pathological provider asking to wait far longer than any
    // schedule position allows. Capping at the schedule's overall
    // MAXIMUM (a previous revision) would release() for that maximum
    // regardless of which attempt this is — which, repeated across
    // several rate-limited attempts, sums to well past uniqueFor() (see
    // the class docblock's "Third correction"). handle() must clamp to
    // THIS attempt's own position instead — here, the first attempt, so
    // backoff()[0].
    fakeProvider()->failNextRefreshWith(new ProviderRateLimitException('slow down', 999_999));

    /** @var RefreshIntegrationTokenJob&MockInterface $job */
    $job = Mockery::mock(RefreshIntegrationTokenJob::class, [$integration])->makePartial();

    // 30, written out, not `$job->backoff()[0]` (final review, batch 4).
    // Computing the expectation from the system under test means the
    // assertion holds for whatever the schedule happens to say — if
    // RETRY_BACKOFF_SECONDS were reordered or its first gap changed to
    // an hour, this test would keep passing while the behaviour it
    // describes ("clamp to THIS attempt's own position", the first) had
    // silently changed. The literal is checked against the schedule just
    // below, so a deliberate change fails here and says so.
    $expectedDelay = 30;
    expect($job->backoff()[0])->toBe($expectedDelay, 'the first backoff gap changed — update this test deliberately');
    $job->shouldReceive('release')->once()->with($expectedDelay);

    $job->handle(app(TokenRefreshService::class));

    $fresh = $integration->fresh();
    expect($fresh->token)->toBe('old-token')
        ->and($fresh->disabled)->toBeFalse();

    Notification::assertNothingSent();
});

it('never releases on the final attempt, even when still rate-limited', function () {
    // The guard in handle(): releasing on the last possible attempt
    // would hold the unique lock for a wait that can never lead to
    // another try (Laravel marks the job failed the moment it next sees
    // attempts() > $tries, without calling handle() again) — so it must
    // not happen at all, not even capped.
    Notification::fake();

    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token' => 'old-token',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    fakeProvider()->failNextRefreshWith(new ProviderRateLimitException('slow down', 30));

    /** @var RefreshIntegrationTokenJob&MockInterface $job */
    $job = Mockery::mock(RefreshIntegrationTokenJob::class, [$integration])->makePartial();
    $job->shouldReceive('attempts')->andReturn($job->tries);
    $job->shouldNotReceive('release');

    $job->handle(app(TokenRefreshService::class));

    Notification::assertNothingSent();
});

// --- ShouldBeUnique lock duration vs. the retry schedule ------------------

it('keeps the unique lock duration at least as long as the worst-case retry lifetime', function () {
    // Proves the invariant the review flagged as broken: uniqueFor()
    // must never be shorter than the time the retry schedule can
    // legitimately keep this job in flight, or ScheduleTokenRefresh's
    // next hourly pass can dispatch a second concurrent job for the
    // same integration while the first is still retrying — the exact
    // race ShouldBeUnique exists to prevent. Deliberately reads only
    // the job's own public backoff()/$tries/uniqueFor() rather than
    // hardcoding the private retry-schedule numbers, so this keeps
    // failing if uniqueFor() is ever reverted to an independent,
    // hand-written constant instead of one derived from the same
    // schedule as backoff()/$tries.
    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
    ]);

    $job = new RefreshIntegrationTokenJob($integration);

    $backoff = $job->backoff();
    $worstCaseRetryDelaySum = array_sum($backoff);

    expect($job->tries)->toBe(count($backoff) + 1)
        ->and($job->uniqueFor())->toBeGreaterThanOrEqual($worstCaseRetryDelaySum);
});

it('keeps the CUMULATIVE rate-limit wait under the unique lock even when every attempt is rate-limited past the schedule', function () {
    // The property the previous round's test failed to check: not "does
    // one release() respect a cap" but "can repeated rate-limited
    // attempts, summed, ever exceed uniqueFor()". Drives the actual
    // handle() path attempt-by-attempt (stubbing attempts() to advance
    // 1, 2, 3, ... exactly like the real queue would across
    // release()/redelivery cycles) rather than asserting the formula
    // the implementation itself uses, so a regression in the cap logic
    // — not just in the arithmetic constants — would be caught here.
    Notification::fake();

    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token' => 'old-token',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    /** @var RefreshIntegrationTokenJob&MockInterface $job */
    $job = Mockery::mock(RefreshIntegrationTokenJob::class, [$integration])->makePartial();

    $releasedDelays = [];
    $job->shouldReceive('release')->andReturnUsing(function (int $delay) use (&$releasedDelays): void {
        $releasedDelays[] = $delay;
    });
    $job->shouldReceive('attempts')->andReturnValues(range(1, $job->tries));

    // Every single attempt hits the rate limit, and the provider always
    // asks for far longer than any schedule position allows — the
    // sustained-rate-limit-window / provider-outage scenario the review
    // flagged as the realistic way to hit this.
    for ($i = 0; $i < $job->tries; $i++) {
        fakeProvider()->failNextRefreshWith(new ProviderRateLimitException('slow down', 999_999));
        $job->handle(app(TokenRefreshService::class));
    }

    expect(array_sum($releasedDelays))->toBeLessThanOrEqual($job->uniqueFor());

    Notification::assertNothingSent();
});

// --- Idempotency / overlap guard -----------------------------------------

it('does not refresh an integration that was disabled since the job was dispatched', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token' => 'old-token',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    $job = new RefreshIntegrationTokenJob($integration);

    $integration->update(['disabled' => true]);

    $job->handle(app(TokenRefreshService::class));

    expect(fakeProvider()->refreshedIntegrationIds())->toBe([]);
});

it('never even queues a second refresh job for the same channel while one is already in flight', function () {
    // Proves the ShouldBeUnique decision documented on
    // RefreshIntegrationTokenJob: a lock held for this integration's
    // uniqueId (as a real in-flight dispatch would hold, via
    // Illuminate\Bus\UniqueLock) must stop a second dispatch() for the
    // SAME integration from ever reaching the queue — not just from
    // running twice. Simulated by acquiring the exact lock Laravel's
    // own PendingDispatch::shouldDispatch() checks, rather than relying
    // on two real dispatches racing (which sync-queue execution would
    // serialize anyway and prove nothing).
    $org = Organization::factory()->create();
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    $inFlight = new RefreshIntegrationTokenJob($integration);
    $lock = Cache::lock(UniqueLock::getKey($inFlight), $inFlight->uniqueFor());
    expect($lock->get())->toBeTrue();

    try {
        RefreshIntegrationTokenJob::dispatch($integration);

        expect(fakeProvider()->refreshedIntegrationIds())->toBe([]);
    } finally {
        $lock->release();
    }
});

// --- Notification anti-spam -----------------------------------------------

it('does not re-notify the owner when the channel is already broken', function () {
    Notification::fake();

    $org = Organization::factory()->create();
    $owner = $org->owner;
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    fakeProvider()->failNextRefreshWith(new ProviderAuthException('revoked'));
    app(TokenRefreshService::class)->refresh($integration->fresh());

    Notification::assertSentToTimes($owner, IntegrationBrokenNotification::class, 1);

    fakeProvider()->failNextRefreshWith(new ProviderAuthException('revoked again'));
    app(TokenRefreshService::class)->refresh($integration->fresh());

    Notification::assertSentToTimes($owner, IntegrationBrokenNotification::class, 1);
});

// --- The actual scheduled entry point -------------------------------------

/*
 * Every test above drives TokenRefreshService or the job directly.
 * `integrations:refresh-tokens` (App\Console\Commands\ScheduleTokenRefresh)
 * is the real production entry point Schedule::command() invokes hourly —
 * a wiring regression there (calling the wrong service method, an empty
 * loop body, a typo in the signature) would pass every test above while
 * the scheduler silently dispatches nothing, forever. These run the
 * actual artisan command with no OrganizationContext set, exactly how
 * the scheduler runs it, and assert on the dispatched jobs via Queue::fake()
 * rather than on side effects, so a wiring bug can't hide behind a
 * coincidentally-correct side effect.
 */

it('dispatches one refresh job per due integration across every tenant when run as the scheduler runs it', function () {
    Queue::fake();
    OrganizationContext::clear();
    expect(OrganizationContext::get())->toBeNull();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $dueA = Integration::factory()->create([
        'organization_id' => $orgA->id,
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);
    $dueB = Integration::factory()->create([
        'organization_id' => $orgB->id,
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(90),
    ]);

    expect(OrganizationContext::get())->toBeNull();

    $this->artisan('integrations:refresh-tokens')->assertSuccessful();

    expect(OrganizationContext::get())->toBeNull();

    Queue::assertPushed(RefreshIntegrationTokenJob::class, 2);
    Queue::assertPushed(fn (RefreshIntegrationTokenJob $job): bool => $job->integration->id === $dueA->id);
    Queue::assertPushed(fn (RefreshIntegrationTokenJob $job): bool => $job->integration->id === $dueB->id);
});

it('dispatches nothing for a disabled, soft-deleted, not-yet-due, or non-refreshable integration', function () {
    Queue::fake();

    $org = Organization::factory()->create();

    Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
        'disabled' => true,
    ]);
    $trashed = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addMinutes(30),
    ]);
    $trashed->delete();
    Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'token_expires_at' => now()->addHours(5),
    ]);
    Integration::factory()->for($org)->create([
        'provider_identifier' => 'telegram',
        'token_expires_at' => now()->addMinutes(30),
    ]);

    $this->artisan('integrations:refresh-tokens')->assertSuccessful();

    Queue::assertNotPushed(RefreshIntegrationTokenJob::class);
});
