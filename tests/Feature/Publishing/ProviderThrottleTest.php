<?php

use App\Enums\OrganizationRole;
use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Services\Posts\PublishService;
use Illuminate\Cache\RateLimiter as RateLimiterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * QUEUE.md Task 7: a shared, cross-tenant, per-provider publish budget —
 * enforced centrally rather than per-worker, so a burst of posts across
 * many organizations for the SAME provider still respects one ceiling.
 *
 * PublishPostJob spans potentially several channels/providers in one
 * handle() call (PublishService::publishGroup() loops per integration),
 * so this is enforced at the per-channel provider-call site
 * (PublishService::publishLockedChannel(), immediately before
 * $provider->post()) rather than as job middleware — see
 * enforceProviderThrottle()'s docblock. It reuses the existing
 * ProviderRateLimitException -> PublishRetryNeededException ->
 * PublishPostJob::release() pipeline verbatim: a throttled channel is
 * released with a delay, never failed.
 *
 * 'fake' (the identifier fakeProvider()/queuedGroup() wire up) carries
 * no config('social.rate_limits') entry by default, so every other test
 * in this suite that publishes a 'fake' channel is unaffected — these
 * tests set the config explicitly, per case, and clear the limiter
 * state before each so one test's consumed budget never leaks into the
 * next.
 */
beforeEach(function () {
    RateLimiter::clear('provider-throttle:fake');
    RateLimiter::clear('provider-throttle:telegram');
});

it('releases a channel whose provider is over its shared budget, instead of publishing or failing', function () {
    config(['social.rate_limits.fake' => ['max' => 1, 'decay_seconds' => 60]]);

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    [, $posts] = publishableChannel($org, $group, ['channel A']);

    // Consume the one slot the budget allows, from a call that has
    // nothing to do with this test's own publish attempt — simulating
    // another tenant's post having already used it this window.
    RateLimiter::hit('provider-throttle:fake', 60);

    $provider = fakeProvider();

    $job = new PublishPostJob($group->id, $group->queue_version);
    $job->withFakeQueueInteractions();

    $job->handle(app(PublishService::class));

    $job->assertReleased();

    expect($provider->publishedChains())->toHaveCount(0)
        ->and($posts[0]->fresh()->state)->toBe(PostState::QUEUE)
        ->and($posts[0]->fresh()->release_id)->toBeNull();
});

it('publishes normally when the provider is under its shared budget', function () {
    config(['social.rate_limits.fake' => ['max' => 5, 'decay_seconds' => 60]]);

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);
    $provider = fakeProvider();

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('does not throttle a provider with no configured rate limit', function () {
    // No config('social.rate_limits.fake') entry at all — the default
    // for every test in this suite that isn't this file.
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['channel A']);
    $provider = fakeProvider();

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED);
});

it('tracks each provider\'s budget independently', function () {
    // Drives a real publish for 'fake' rather than asserting on
    // RateLimiter's read-only tooManyAttempts() directly (post-review fix,
    // round 4): enforceProviderThrottle() no longer calls that method at
    // all, so a test written against it would keep "passing" even if this
    // property broke. 'telegram' is checked via its own counter rather
    // than a real publish attempt, to keep this deterministic — a real
    // TelegramProvider call needs real bot credentials this test has none
    // of, and a throttle-independence test has no business depending on
    // that provider's own network/auth behaviour.
    config([
        'social.rate_limits.fake' => ['max' => 1, 'decay_seconds' => 60],
        'social.rate_limits.telegram' => ['max' => 1, 'decay_seconds' => 60],
    ]);
    RateLimiter::clear('provider-throttle:telegram');

    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['fake channel']);

    // max => 2: one slot pre-consumed below, one for the real publish —
    // proves the assertion isn't vacuously true because fake's own count
    // never moved, without also tripping fake's OWN throttle.
    config(['social.rate_limits.fake' => ['max' => 2, 'decay_seconds' => 60]]);
    RateLimiter::hit('provider-throttle:fake', 60);

    $provider = fakeProvider();

    app(PublishService::class)->publishGroup($group->id);

    expect($provider->publishedChains())->toHaveCount(1)
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and(RateLimiter::attempts('provider-throttle:fake'))->toBe(2)
        ->and(RateLimiter::attempts('provider-throttle:telegram'))->toBe(0);
});

// --- Post-review fix (round 4, P1): the throttle must be one atomic
// operation, not a separate check-then-increment -------------------------

/**
 * One organization, one 'fake' channel, one QUEUE group due now — the
 * minimal committed (race-connection) fixture a single racer needs to
 * drive PublishService::publishGroup() for real. Mirrors
 * PublishRaceTest.php's publishRaceFixture(), simplified to one channel.
 *
 * @return array{group: PostGroup, cleanup: Closure(): void}
 */
function providerThrottleRaceFixture(string $label): array
{
    $owner = raceCreate(User::class, [
        'name' => 'Throttle Race Owner',
        'email' => 'throttle-race-'.$label.'-'.Str::random(10).'@example.com',
        'password' => 'irrelevant-hash',
    ]);
    $organization = raceCreate(Organization::class, [
        'name' => 'Throttle Race Co',
        'slug' => 'throttle-race-'.$label.'-'.Str::lower(Str::random(10)),
        'owner_id' => $owner->id,
    ]);
    raceCreate(OrganizationUser::class, [
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationRole::ADMIN,
    ]);
    $integration = raceCreate(Integration::class, [
        'organization_id' => $organization->id,
        'provider_identifier' => 'fake',
        'internal_id' => (string) Str::ulid(),
        'name' => 'throttle race channel '.$label,
    ]);
    $group = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'state' => PostState::QUEUE,
        'publish_date' => now(),
        'queue_version' => 1,
    ]);
    raceCreate(Post::class, [
        'post_group_id' => $group->id,
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'throttle race content '.$label,
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);

    return [
        'group' => $group,
        'cleanup' => function () use ($group, $organization, $owner): void {
            DB::connection('race')->table('posts')->where('post_group_id', $group->id)->delete();
            DB::connection('race')->table('post_groups')->where('id', $group->id)->delete();
            DB::connection('race')->table('integrations')->where('organization_id', $organization->id)->delete();
            DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
            DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
            DB::connection('race')->table('users')->where('id', $owner->id)->delete();
        },
    ];
}

/**
 * Touches this racer's own ready-file, then blocks until every racer's
 * ready-file exists (or times out) — makes the N publishes actually
 * overlap inside enforceProviderThrottle() instead of merely running
 * "concurrently" in name while accidentally serializing on fork-startup
 * jitter, which would let the pre-fix check-then-increment bug hide.
 */
function providerThrottleAwaitAllReady(string $dir, int $index, int $total, int $timeoutMicroseconds = 10_000_000): void
{
    touch("{$dir}/ready-{$index}");

    $waited = 0;
    $step = 500;

    while (count(glob("{$dir}/ready-*") ?: []) < $total) {
        if ($waited >= $timeoutMicroseconds) {
            throw new RuntimeException('Not every throttle racer became ready in time.');
        }

        usleep($step);
        $waited += $step;
        clearstatcache();
    }
}

it('enforces the shared budget atomically: several genuinely concurrent publishes for the SAME provider let exactly one through', function () {
    // Proves the fix directly: the pre-fix enforceProviderThrottle() did
    // a separate RateLimiter::tooManyAttempts() (read) then
    // RateLimiter::hit() (write) — two cache round-trips with nothing
    // atomic between them. N truly concurrent OS processes can each pass
    // the read before any of them commits a write, all sail past a
    // budget of 1. Sequential Pest calls (the tests above) can never
    // reproduce this: each call's read-then-write pair fully completes
    // before the next one starts.
    config(['social.rate_limits.fake' => ['max' => 1, 'decay_seconds' => 60]]);

    $originalCacheDefault = config('cache.default');
    config(['cache.default' => 'redis']);

    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $racerCount = 5;
    $fixtures = [];
    for ($i = 0; $i < $racerCount; $i++) {
        $fixtures[$i] = providerThrottleRaceFixture((string) $i);
    }

    $barrierDir = sys_get_temp_dir().'/pest-throttle-barrier-'.bin2hex(random_bytes(6));
    mkdir($barrierDir);

    try {
        // Cleared directly on the redis store, not via the RateLimiter
        // FACADE: switching config('cache.default') above does not
        // rebind RateLimiter's own container singleton (see
        // CacheServiceProvider — it resolves its cache driver once and
        // is cached both as a container singleton and inside the Facade
        // itself), so if anything earlier in this process already
        // resolved RateLimiter against the array store, RateLimiter::clear()
        // here would silently no-op against redis. This is real Redis,
        // shared across separate test-suite invocations too — a
        // previous run of this exact test leaves 'provider-throttle:fake'
        // live for its whole decay window otherwise, and a leftover
        // elevated count here would make every racer see itself as
        // already over budget, deterministically producing ZERO
        // successes rather than proving anything about atomicity.
        Cache::store('redis')->forget('provider-throttle:fake');
        Cache::store('redis')->forget('provider-throttle:fake:timer');

        $racers = [];
        foreach ($fixtures as $i => $fixture) {
            $racers[] = function () use ($fixture, $barrierDir, $i, $racerCount) {
                raceUseFreshRedisConnection();

                // The RateLimiter singleton (bound once per container
                // lifetime — see CacheServiceProvider) may already have
                // been resolved against the array store inherited from
                // the parent process before cache.default was switched.
                // Forgetting both the container binding and the Facade's
                // own resolved-instance cache forces a fresh RateLimiter
                // built against the connection raceUseFreshRedisConnection()
                // just configured.
                app()->forgetInstance(RateLimiterService::class);
                RateLimiter::clearResolvedInstance(RateLimiterService::class);

                providerThrottleAwaitAllReady($barrierDir, $i, $racerCount);

                app(PublishService::class)->publishGroup($fixture['group']->id, 1);
            };
        }

        $results = raceConcurrently($racers);

        $succeeded = array_filter($results, fn ($r) => $r === 'ok');
        $failed = array_filter($results, fn ($r) => $r !== 'ok');

        // Exactly one of the N genuinely-concurrent publishes may win a
        // budget of 1; the pre-fix check-then-increment implementation
        // could let more than one through. Every loser must be a clean
        // throttle-triggered retry, never a duplicate publish and never
        // a crash.
        expect($succeeded)->toHaveCount(1);
        foreach ($failed as $result) {
            expect($result)->toBe('fail:'.PublishRetryNeededException::class);
        }
    } finally {
        config(['cache.default' => $originalCacheDefault]);
        foreach ($fixtures as $fixture) {
            $fixture['cleanup']();
        }
        foreach (glob("{$barrierDir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($barrierDir);
    }
});
