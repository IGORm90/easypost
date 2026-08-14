<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * These tests exercise Organization/User/OrganizationUser against the real
 * (migrated) database rather than mocks, since what's under test is the
 * pivot relationship and the unique constraint that backs it. This file
 * lives in tests/Unit (no RefreshDatabase, per tests/Pest.php), matching
 * the existing convention set by BelongsToOrganizationTest and
 * ResolveOrganizationMiddlewareTest for tests that need direct DB access
 * without going through the HTTP kernel.
 */
it('returns the role of a member', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->addMember($user, OrganizationRole::ADMIN);

    expect($org->roleOf($user))->toBe(OrganizationRole::ADMIN);
    expect($org->roleOf(User::factory()->create()))->toBeNull();
});

it('returns null for a user who has never been a member of the organization', function () {
    $org = Organization::factory()->create();
    $nonMember = User::factory()->create();

    expect($org->roleOf($nonMember))->toBeNull();
});

it('reads the role from an eager-loaded members relation with zero additional queries', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->addMember($user, OrganizationRole::USER);

    $loaded = Organization::with('members')->findOrFail($org->id);
    expect($loaded->relationLoaded('members'))->toBeTrue();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $role = $loaded->roleOf($user);

    expect(DB::getQueryLog())->toBeEmpty();
    expect($role)->toBe(OrganizationRole::USER);

    DB::disableQueryLog();
});

it('runs exactly one query for roleOf when members is not eager-loaded', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->addMember($user, OrganizationRole::USER);

    $fresh = Organization::findOrFail($org->id);
    expect($fresh->relationLoaded('members'))->toBeFalse();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $fresh->roleOf($user);

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});

it('rejects a duplicate membership for the same organization and user', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();

    $org->addMember($user, OrganizationRole::USER);

    expect(fn () => $org->addMember($user, OrganizationRole::ADMIN))
        ->toThrow(QueryException::class);
});

it('creates a membership via addMember with no explicit pivot id, unlike attach()', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();

    $membership = $org->addMember($user, OrganizationRole::ADMIN);

    expect($membership)->toBeInstanceOf(OrganizationUser::class);
    expect($membership->id)->not->toBeNull();
    expect($membership->id)->toHaveLength(26);
    expect($membership->role)->toBe(OrganizationRole::ADMIN);
    expect($membership->organization_id)->toBe($org->id);
    expect($membership->user_id)->toBe($user->id);
});

it('also generates a pivot id via bare attach(), because members() uses a custom pivot class', function () {
    // organization_user.id has no database default, which would ordinarily
    // make a bare attach() (no explicit 'id') unsafe: attach() normally
    // inserts through the query builder directly, bypassing model events.
    // Organization::members() is declared with ->using(OrganizationUser::class)
    // though, which makes attach() route through attachUsingCustomClass()
    // (a real OrganizationUser::save() per row) instead — so HasUlids'
    // creating hook does fire here. Verified directly against the row, not
    // just "no exception was thrown".
    $org = Organization::factory()->create();
    $user = User::factory()->create();

    $org->members()->attach($user, ['role' => 'ADMIN']);

    $membership = OrganizationUser::where('organization_id', $org->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->id)->not->toBeNull();
    expect($membership->id)->toHaveLength(26);
});

it('scopes organization_user rows to one organization via forOrganization', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $orgA->addMember($userA, OrganizationRole::ADMIN);
    $orgB->addMember($userB, OrganizationRole::USER);

    $rows = OrganizationUser::forOrganization($orgA)->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->organization_id)->toBe($orgA->id);
    expect($rows->pluck('organization_id'))->not->toContain($orgB->id);
});
