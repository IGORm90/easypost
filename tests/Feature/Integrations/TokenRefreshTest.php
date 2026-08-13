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

    fakeProvider()->failNextRefreshWith(new ProviderRateLimitException('slow down', 45));

    /** @var RefreshIntegrationTokenJob&MockInterface $job */
    $job = Mockery::mock(RefreshIntegrationTokenJob::class, [$integration])->makePartial();
    $job->shouldReceive('release')->once()->with(45);

    $job->handle(app(TokenRefreshService::class));

    $fresh = $integration->fresh();
    expect($fresh->token)->toBe('old-token')
        ->and($fresh->disabled)->toBeFalse();

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
