<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

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
    $org->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => 'ADMIN']);

    expect($org->roleOf($user))->toBe(OrganizationRole::ADMIN);
    expect($org->roleOf(User::factory()->create()))->toBeNull();
});

it('returns null for a user who has never been a member of the organization', function () {
    $org = Organization::factory()->create();
    $nonMember = User::factory()->create();

    expect($org->roleOf($nonMember))->toBeNull();
});

it('reads the role from an eager-loaded members relation without an extra query', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => 'USER']);

    $loaded = Organization::with('members')->findOrFail($org->id);

    expect($loaded->relationLoaded('members'))->toBeTrue();
    expect($loaded->roleOf($user))->toBe(OrganizationRole::USER);
});

it('rejects a duplicate membership for the same organization and user', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();

    $org->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => 'USER']);

    expect(fn () => $org->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => 'ADMIN']))
        ->toThrow(QueryException::class);
});
