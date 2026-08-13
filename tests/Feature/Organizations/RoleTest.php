<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Organizations\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('lets an ADMIN list members', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $this->getJson('/api/organizations/current/members')->assertOk();
});

it('lets a SUPERADMIN satisfy an ADMIN-gated route (roles are hierarchical)', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'SUPERADMIN');

    $this->postJson('/api/organizations/current/invites', ['email' => 'x@e.com', 'role' => 'USER'])
        ->assertCreated();
});

it('does not leak another organization\'s members in the member listing', function () {
    $mine = Organization::factory()->create();
    $other = Organization::factory()->create();
    actingAsMember($mine, 'ADMIN');
    $other->addMember(User::factory()->create(['name' => 'Stranger']), OrganizationRole::USER);

    $response = $this->getJson('/api/organizations/current/members')->assertOk();

    $names = collect((array) $response->json())->pluck('name');
    expect($names)->not->toContain('Stranger');
});

it('lets an ADMIN remove a USER member', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');
    $member = User::factory()->create();
    $org->addMember($member, OrganizationRole::USER);

    $this->deleteJson("/api/organizations/current/members/{$member->id}")->assertNoContent();

    expect($org->fresh()->roleOf($member))->toBeNull();
});

it('forbids a USER from removing members', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'USER');
    $member = User::factory()->create();
    $org->addMember($member, OrganizationRole::USER);

    $this->deleteJson("/api/organizations/current/members/{$member->id}")->assertForbidden();
});

it('lets an ADMIN change a USER role to ADMIN', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');
    $member = User::factory()->create();
    $org->addMember($member, OrganizationRole::USER);

    $this->putJson("/api/organizations/current/members/{$member->id}/role", ['role' => 'ADMIN'])
        ->assertOk();

    expect($org->fresh()->roleOf($member))->toBe(OrganizationRole::ADMIN);
});

it('refuses to remove the last SUPERADMIN with a 422', function () {
    $org = Organization::factory()->create();
    $superadmin = actingAsMember($org, 'SUPERADMIN');

    $this->deleteJson("/api/organizations/current/members/{$superadmin->id}")
        ->assertStatus(422);

    expect($org->fresh()->roleOf($superadmin))->toBe(OrganizationRole::SUPERADMIN);
});

it('refuses to demote the last SUPERADMIN with a 422', function () {
    $org = Organization::factory()->create();
    $superadmin = actingAsMember($org, 'SUPERADMIN');

    $this->putJson("/api/organizations/current/members/{$superadmin->id}/role", ['role' => 'ADMIN'])
        ->assertStatus(422);

    expect($org->fresh()->roleOf($superadmin))->toBe(OrganizationRole::SUPERADMIN);
});

it('allows removing a SUPERADMIN when another SUPERADMIN remains', function () {
    $org = Organization::factory()->create();
    $actingSuperadmin = actingAsMember($org, 'SUPERADMIN');
    $otherSuperadmin = User::factory()->create();
    $org->addMember($otherSuperadmin, OrganizationRole::SUPERADMIN);

    $this->deleteJson("/api/organizations/current/members/{$otherSuperadmin->id}")->assertNoContent();

    expect($org->fresh()->roleOf($otherSuperadmin))->toBeNull();
    expect($org->fresh()->roleOf($actingSuperadmin))->toBe(OrganizationRole::SUPERADMIN);
});

it('forbids an ADMIN from removing a SUPERADMIN', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');
    $superadmin = User::factory()->create();
    $org->addMember($superadmin, OrganizationRole::SUPERADMIN);
    // A second SUPERADMIN so this isn't the last-SUPERADMIN 422 case.
    $org->addMember(User::factory()->create(), OrganizationRole::SUPERADMIN);

    $this->deleteJson("/api/organizations/current/members/{$superadmin->id}")->assertForbidden();
});

it('forbids an ADMIN from promoting a member to SUPERADMIN', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');
    $member = User::factory()->create();
    $org->addMember($member, OrganizationRole::USER);

    $this->putJson("/api/organizations/current/members/{$member->id}/role", ['role' => 'SUPERADMIN'])
        ->assertForbidden();
});

it('never drops an organization to zero SUPERADMINs when demotions genuinely race', function () {
    // Sequential Pest/HTTP calls (like the two 422 tests above) can never
    // reproduce this: each call's transaction fully commits before the
    // next one starts, so there is never a moment where two demotions are
    // both actually in flight together. Reproducing the race requires
    // real OS-level concurrency -- see raceConcurrently() in
    // tests/Pest.php.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    // Fixtures are created through the "race" connection (plain
    // autocommit, not wrapped in RefreshDatabase's open transaction on
    // the default connection) so they are immediately visible to the
    // forked child processes below, which cannot see anything still
    // sitting in that uncommitted transaction.
    $owner = raceCreate(User::class, ['name' => 'Race Owner', 'email' => 'race-owner-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Race Co', 'slug' => 'race-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);

    $superadminCount = 4;
    $superadmins = [];
    for ($i = 0; $i < $superadminCount; $i++) {
        $user = raceCreate(User::class, ['name' => "Superadmin {$i}", 'email' => "race-superadmin-{$i}-".Str::random(6).'@example.com', 'password' => 'irrelevant-hash']);
        raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => OrganizationRole::SUPERADMIN]);
        $superadmins[] = $user;
    }

    $organizationId = $organization->id;

    try {
        $racers = array_map(
            fn (User $superadmin) => function () use ($organizationId, $superadmin) {
                // Re-fetched fresh inside the child, on whichever
                // connection raceConcurrently() has just pointed
                // "default" at -- never the "race"-pinned instances
                // captured before forking. Those share one live,
                // duplicated PDO socket across every forked child (see
                // raceConcurrently()'s docblock); querying through them
                // from more than one child at once would corrupt it.
                $organization = Organization::findOrFail($organizationId);
                $actor = User::findOrFail($superadmin->id);

                app(MembershipService::class)->updateRole($organization, $actor, $actor, OrganizationRole::ADMIN);
            },
            $superadmins
        );

        $results = raceConcurrently($racers);

        $succeeded = array_filter($results, fn ($r) => $r === 'ok');
        $failed = array_filter($results, fn ($r) => $r !== 'ok');

        // Every one of the $superadminCount SUPERADMINs concurrently
        // demotes *itself*. At most $superadminCount - 1 may succeed: one
        // must always be refused, or the organization is left with zero
        // SUPERADMINs -- a state nobody remaining could ever recover
        // from. The unlocked guard let every concurrent caller read
        // "more than one left" before any of them had committed, letting
        // all of them through.
        expect($succeeded)->toHaveCount($superadminCount - 1);
        foreach ($failed as $result) {
            expect($result)->toBe('fail:422');
        }

        $remainingSuperadmins = DB::connection('race')->table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('role', 'SUPERADMIN')
            ->count();

        expect($remainingSuperadmins)->toBe(1);
    } finally {
        DB::connection('race')->table('organization_user')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organizations')->where('id', $organizationId)->delete();
        DB::connection('race')->table('users')->whereIn('id', [$owner->id, ...array_map(fn ($u) => $u->id, $superadmins)])->delete();
    }
});

it('returns 404 when acting on a user who is not a member of the current organization', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');
    $stranger = User::factory()->create();

    $this->deleteJson("/api/organizations/current/members/{$stranger->id}")->assertNotFound();
});
