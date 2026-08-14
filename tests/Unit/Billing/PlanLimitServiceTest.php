<?php

use App\Enums\OrganizationRole;
use App\Enums\Plan;
use App\Enums\PlanLimit;
use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Billing\Exceptions\PlanLimitExceededException;
use App\Services\Billing\PlanLimitService;
use App\Services\Billing\PlanRegistry;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function () {
    // These tests are Unit (no RefreshDatabase, see tests/Pest.php) and
    // several call OrganizationContext::set() directly -- clearing it here
    // matches BelongsToOrganizationTest's convention and stops that static
    // state from leaking into whichever test runs next in this process.
    OrganizationContext::clear();
});

it('falls back to the free plan for an organization without a plan', function () {
    $org = Organization::factory()->create(['plan' => null]);

    expect(app(PlanRegistry::class)->forOrganization($org)['name'])->toBe('Free');
});

it('allows creation within the plan limit', function () {
    $org = Organization::factory()->create(['plan' => 'free']); // 2 channels
    OrganizationContext::set($org);
    Integration::factory()->for($org)->create();

    app(PlanLimitService::class)->check($org, PlanLimit::CHANNELS); // does not throw
})->throwsNoExceptions();

it('throws when the channel limit is reached', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    OrganizationContext::set($org);
    Integration::factory()->for($org)->count(2)->create();

    app(PlanLimitService::class)->check($org, PlanLimit::CHANNELS);
})->throws(PlanLimitExceededException::class);

it('treats a null limit as unlimited, short-circuiting before counting anything', function () {
    $org = Organization::factory()->create(['plan' => 'pro']); // posts_per_month: null
    OrganizationContext::set($org);

    DB::enableQueryLog();
    DB::flushQueryLog();

    // A huge delta, no post_groups rows created at all: if this ever
    // queried "current usage" before noticing the limit is null, it would
    // still pass (nothing to compare against), but the query log proves
    // it never even tries -- a null max short-circuits.
    app(PlanLimitService::class)->check($org, PlanLimit::POSTS_PER_MONTH, 10_000);

    $ranACountQuery = collect(DB::getQueryLog())->contains(
        fn (array $entry) => str_contains(strtolower($entry['query']), 'count')
    );

    DB::disableQueryLog();

    expect($ranACountQuery)->toBeFalse();
});

it('ignores soft-deleted integrations in the channel count', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    OrganizationContext::set($org);

    Integration::factory()->for($org)->create();
    $deleted = Integration::factory()->for($org)->create();
    $deleted->delete();

    // Only one live integration remains against a limit of 2 -- adding
    // one more must not throw, proving the soft-deleted row is not
    // counted.
    app(PlanLimitService::class)->check($org, PlanLimit::CHANNELS);

    expect(app(PlanLimitService::class)->usage($org)[PlanLimit::CHANNELS->value]['current'])->toBe(1);
});

it('resets the monthly post counter in a new period', function () {
    $org = Organization::factory()->create(['plan' => 'free']); // 30 posts/month
    OrganizationContext::set($org);

    // A post_group genuinely created last UTC month -- bypassing
    // Eloquent's automatic timestamping via a raw update, since
    // PostGroupFactory (like any factory) can't backdate created_at
    // through a normal ->create() call.
    $stale = PostGroup::factory()->for($org)->create();
    DB::table('post_groups')->where('id', $stale->id)->update([
        'created_at' => Date::now('UTC')->subMonthNoOverflow(),
    ]);

    // A post_group genuinely created in the current period.
    PostGroup::factory()->for($org)->create();

    // The stale period's row must not count towards the current one --
    // counting on demand from post_groups.created_at means there is
    // nothing to "reset": a new UTC month is simply a different window.
    expect(app(PlanLimitService::class)->usage($org)[PlanLimit::POSTS_PER_MONTH->value]['current'])->toBe(1);
});

it('changes the plan through the console command', function () {
    $org = Organization::factory()->create(['plan' => 'free']);

    $this->artisan("org:plan {$org->id} pro")->assertSuccessful();

    expect($org->fresh()->plan)->toBe(Plan::PRO);
});

it('compares media storage in bytes, rounding only once for display', function () {
    $org = Organization::factory()->create(['plan' => 'free']); // 200 MB limit
    OrganizationContext::set($org);

    // Two files at 0.4 MB each sum to 0.8 MB raw. Rounding each
    // individually before comparing (ceil(0.4) + ceil(0.4) = 2 MB) was
    // the pre-fix bug; comparing in bytes and rounding only once, for
    // display, correctly reads this as 1 MB.
    $bytesPerFile = (int) (0.4 * 1024 * 1024);
    Media::factory()->for($org)->create(['size_bytes' => $bytesPerFile]);

    app(PlanLimitService::class)->check($org, PlanLimit::MEDIA_STORAGE_MB, $bytesPerFile);

    expect(app(PlanLimitService::class)->usage($org)[PlanLimit::MEDIA_STORAGE_MB->value]['current'])->toBe(1);
});

it('rejects a delta below 1', function () {
    $org = Organization::factory()->create(['plan' => 'free']);

    app(PlanLimitService::class)->check($org, PlanLimit::CHANNELS, 0);
})->throws(InvalidArgumentException::class);

it('rejects a negative delta', function () {
    $org = Organization::factory()->create(['plan' => 'free']);

    app(PlanLimitService::class)->check($org, PlanLimit::CHANNELS, -5);
})->throws(InvalidArgumentException::class);

it('includes the UTC reset period alongside posts_per_month usage', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    OrganizationContext::set($org);

    $usage = app(PlanLimitService::class)->usage($org);

    expect($usage[PlanLimit::POSTS_PER_MONTH->value]['period'])->toBe(Date::now('UTC')->format('Y-m'));
});

it('never lets two concurrent post creations both pass a limit of one', function () {
    // Sequential Pest/HTTP calls can never reproduce this race -- each
    // call's transaction fully commits before the next one starts. See
    // raceConcurrently() in tests/Pest.php.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Plan Race Owner', 'email' => 'plan-race-owner-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Plan Race Co', 'slug' => 'plan-race-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id, 'plan' => 'free']);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);

    $originalLimits = config('plans.free.limits');
    config(['plans.free.limits' => array_merge($originalLimits, ['posts_per_month' => 1])]);

    $organizationId = $organization->id;
    $ownerId = $owner->id;
    $racerCount = 5;

    try {
        $racers = array_map(
            fn () => function () use ($organizationId, $ownerId) {
                $organization = Organization::findOrFail($organizationId);

                app(PlanLimitService::class)->withLimit(
                    $organization,
                    PlanLimit::POSTS_PER_MONTH,
                    1,
                    function () use ($organization, $ownerId) {
                        PostGroup::query()->create([
                            'organization_id' => $organization->id,
                            'state' => PostState::DRAFT,
                            'publish_date' => now()->addDay(),
                            'created_by' => $ownerId,
                            'queue_version' => 0,
                        ]);
                    },
                );
            },
            range(1, $racerCount),
        );

        $results = raceConcurrently($racers);

        $succeeded = array_filter($results, fn ($r) => $r === 'ok');
        $failed = array_filter($results, fn ($r) => $r !== 'ok');

        expect($succeeded)->toHaveCount(1);
        foreach ($failed as $result) {
            expect($result)->toBe('fail:App\Services\Billing\Exceptions\PlanLimitExceededException');
        }

        $count = DB::connection('race')->table('post_groups')
            ->where('organization_id', $organizationId)
            ->count();

        expect($count)->toBe(1);
    } finally {
        config(['plans.free.limits' => $originalLimits]);
        DB::connection('race')->table('post_groups')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organization_user')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organizations')->where('id', $organizationId)->delete();
        DB::connection('race')->table('users')->where('id', $ownerId)->delete();
    }
});

it('never lets two concurrent channel creations both pass a limit of one, via withLimit', function () {
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Channel Race Owner', 'email' => 'channel-race-owner-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Channel Race Co', 'slug' => 'channel-race-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id, 'plan' => 'free']);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);

    $originalLimits = config('plans.free.limits');
    config(['plans.free.limits' => array_merge($originalLimits, ['channels' => 1])]);

    $organizationId = $organization->id;
    $racerCount = 5;

    try {
        $racers = array_map(
            fn (int $i) => function () use ($organizationId, $i) {
                $organization = Organization::findOrFail($organizationId);

                app(PlanLimitService::class)->withLimit(
                    $organization,
                    PlanLimit::CHANNELS,
                    1,
                    function () use ($organization, $i) {
                        Integration::query()->create([
                            'organization_id' => $organization->id,
                            'provider_identifier' => 'fake',
                            'internal_id' => "race-channel-{$i}",
                            'name' => "Race Channel {$i}",
                            'token' => 'race-token',
                            'refresh_token' => 'race-refresh',
                            'settings' => [],
                        ]);
                    },
                );
            },
            range(1, $racerCount),
        );

        $results = raceConcurrently($racers);

        $succeeded = array_filter($results, fn ($r) => $r === 'ok');
        $failed = array_filter($results, fn ($r) => $r !== 'ok');

        expect($succeeded)->toHaveCount(1);
        foreach ($failed as $result) {
            expect($result)->toBe('fail:App\Services\Billing\Exceptions\PlanLimitExceededException');
        }

        $count = DB::connection('race')->table('integrations')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->count();

        expect($count)->toBe(1);
    } finally {
        config(['plans.free.limits' => $originalLimits]);
        DB::connection('race')->table('integrations')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organization_user')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organizations')->where('id', $organizationId)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});
