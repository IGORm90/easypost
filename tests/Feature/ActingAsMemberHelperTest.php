<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;

/*
 * actingAsMember() (tests/Pest.php) was written in Task 2, ahead of
 * Organization/User/organization_user existing, and had never actually run
 * against real tables until this task's migrations landed. This proves the
 * DB-facing half of it works: it attaches the acting user to the
 * organization with an explicit pivot ULID and authenticates as them. (The
 * X-Organization default-header half relies on Laravel's own well-tested
 * withHeader() test helper and isn't re-verified here; no route exists yet
 * to exercise it end to end — that's for the task that first adds one.)
 */
it('attaches the acting user as a real member with the requested role', function () {
    $org = Organization::factory()->create();

    $user = actingAsMember($org, 'ADMIN');

    expect($org->roleOf($user))->toBe(OrganizationRole::ADMIN);
    expect(auth()->user()?->id)->toBe($user->id);
});

it('defaults to the ADMIN role when none is given', function () {
    $org = Organization::factory()->create();

    $user = actingAsMember($org);

    expect($org->roleOf($user))->toBe(OrganizationRole::ADMIN);
});
