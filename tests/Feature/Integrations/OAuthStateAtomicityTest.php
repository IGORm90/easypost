<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Integrations\OAuthStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Task 13 review fix: OAuthStateService::consume() must be single-use
 * ATOMICALLY, not just when nobody double-submits. Sequential Pest/HTTP
 * calls (as in IntegrationApiTest's "single-use" test) can never prove
 * this — each call fully completes, lock included, before the next one
 * starts, so there is never a moment where two consume() calls for the
 * same state are genuinely both in flight. This file follows the
 * pcntl_fork() harness InviteTest established for the same class of
 * problem (see tests/Pest.php's raceConcurrently()/raceCreate()).
 *
 * Two things need real OS-level concurrency here, not just a real
 * database like InviteTest's race: the STATE ITSELF lives in the cache,
 * not the database, so the test must also run against a genuinely
 * shared cache store (Redis — the project's production default; the
 * 'array' store forced in tests via phpunit.xml is per-process and
 * cannot prove anything about cross-process exclusion, since fork()
 * gives each child an independent in-memory copy of it). And exactly
 * like the Postgres PDO connection documented in raceConcurrently(),
 * fork() duplicates the parent's already-open Redis socket into every
 * child — two children issuing commands over that same inherited
 * socket concurrently would corrupt the RESP protocol stream for both.
 * raceUseFreshRedisConnection() below is the Redis-side counterpart of
 * raceConcurrently()'s DB connection trick: it points the redis cache
 * store at a connection name unique to this child's pid, which was
 * never resolved before the fork, so Laravel opens a brand-new socket
 * for it on first use instead of touching the inherited one — while
 * still talking to the exact same external Redis server, where the
 * state entry the parent wrote is already visible to any connection.
 */
it('consumes a state atomically: two genuinely concurrent callbacks yield exactly one success', function () {
    $originalCacheDefault = config('cache.default');
    config(['cache.default' => 'redis']);

    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    // Fixtures on the 'race' connection (plain autocommit), exactly as
    // InviteTest does: forked children reconnect from scratch and cannot
    // see anything still sitting inside RefreshDatabase's open
    // transaction on the parent's default connection.
    $owner = raceCreate(User::class, ['name' => 'State Race Owner', 'email' => 'state-race-owner-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'State Race Co', 'slug' => 'state-race-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);

    // Minted on the real, shared Redis store (cache.default was just
    // switched above) so it is visible to every forked child regardless
    // of which local connection name each one ends up using.
    $state = app(OAuthStateService::class)->generate($organization, $owner);

    try {
        $racers = array_map(
            fn () => function () use ($state, $owner) {
                raceUseFreshRedisConnection();
                app(OAuthStateService::class)->consume($state, $owner);
            },
            range(1, 5),
        );

        $results = raceConcurrently($racers);

        // Exactly one of the N genuinely-concurrent consume() calls may
        // win; the pre-fix implementation (separate get()/forget() with
        // no lock between them) could let more than one through. Every
        // loser must fail with the ordinary "invalid state" outcome
        // (already consumed by the winner by the time it got the lock),
        // never a crash or a partial/duplicated Organization resolution.
        $succeeded = array_filter($results, fn ($r) => $r === 'ok');
        $failed = array_filter($results, fn ($r) => $r !== 'ok');

        expect($succeeded)->toHaveCount(1);
        foreach ($failed as $result) {
            expect($result)->toBe('fail:App\Services\Integrations\Exceptions\InvalidOAuthStateException');
        }
    } finally {
        config(['cache.default' => $originalCacheDefault]);
        DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});

// raceUseFreshRedisConnection() used to live here. Post-review fix (round
// 4): promoted to tests/Pest.php alongside raceConcurrently()/raceCreate()/
// raceAwaitBarrier(), since ProviderThrottleTest now needs the identical
// Redis-race setup and a helper declared in one test file only works on a
// full-suite run (see tests/Pest.php's own "Publishing-pipeline fixtures"
// comment for why every shared racer helper lives there).
