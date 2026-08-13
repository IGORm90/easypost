<?php

use App\Enums\OrganizationRole;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\InviteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('forbids a USER role from inviting members', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'USER');
    $this->postJson('/api/organizations/current/invites', ['email' => 'x@e.com', 'role' => 'USER'])
        ->assertForbidden();
});

it('adds the invitee as a member on accept', function () {
    $org = Organization::factory()->create();
    $invite = Invite::factory()->for($org)->create(['role' => 'USER', 'expires_at' => now()->addDay()]);
    $user = User::factory()->create(['email' => $invite->email]);
    $this->actingAs($user)->postJson("/api/invites/{$invite->token}/accept")->assertOk();
    expect($org->fresh()->roleOf($user))->toBe(OrganizationRole::USER);
});

it('rejects an expired invite', function () {
    $org = Organization::factory()->create();
    $invite = Invite::factory()->for($org)->expired()->create();
    $user = User::factory()->create(['email' => $invite->email]);

    $this->actingAs($user)->postJson("/api/invites/{$invite->token}/accept")
        ->assertStatus(410);
});

it('lets an ADMIN create an invite and returns a url', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $response = $this->postJson('/api/organizations/current/invites', [
        'email' => 'newbie@example.com',
        'role' => 'USER',
    ])->assertCreated();

    $response->assertJsonStructure(['url']);
    expect(Invite::where('email', 'newbie@example.com')->forOrganization($org)->exists())->toBeTrue();
});

it('generates unguessable, non-sequential invite tokens', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $first = $this->postJson('/api/organizations/current/invites', ['email' => 'a@e.com', 'role' => 'USER'])
        ->assertCreated()->json('url');
    $second = $this->postJson('/api/organizations/current/invites', ['email' => 'b@e.com', 'role' => 'USER'])
        ->assertCreated()->json('url');

    $firstToken = Str::afterLast($first, '/');
    $secondToken = Str::afterLast($second, '/');

    expect($firstToken)->not->toBe($secondToken);
    expect(strlen($firstToken))->toBeGreaterThanOrEqual(32);
    expect($firstToken)->not->toContain('a@e.com');
});

it('prevents an ADMIN from minting a SUPERADMIN invite', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $this->postJson('/api/organizations/current/invites', [
        'email' => 'wannabe@example.com',
        'role' => 'SUPERADMIN',
    ])->assertForbidden();
});

it('lets a SUPERADMIN mint a SUPERADMIN invite', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'SUPERADMIN');

    $this->postJson('/api/organizations/current/invites', [
        'email' => 'trusted@example.com',
        'role' => 'SUPERADMIN',
    ])->assertCreated();
});

it('creates a shareable (null-email) invite end to end via the create endpoint', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $url = $this->postJson('/api/organizations/current/invites', ['role' => 'USER'])
        ->assertCreated()
        ->json('url');

    $token = Str::afterLast($url, '/');
    $invite = Invite::where('token', $token)->firstOrFail();
    expect($invite->email)->toBeNull();

    $anyUser = User::factory()->create();
    $this->actingAs($anyUser)->postJson("/api/invites/{$token}/accept")->assertOk();

    expect($org->fresh()->roleOf($anyUser))->toBe(OrganizationRole::USER);
});

it('rejects accepting an invite addressed to a different email than the authenticated user', function () {
    $org = Organization::factory()->create();
    $invite = Invite::factory()->for($org)->create(['email' => 'intended@example.com', 'expires_at' => now()->addDay()]);
    $wrongUser = User::factory()->create(['email' => 'someone-else@example.com']);

    $this->actingAs($wrongUser)->postJson("/api/invites/{$invite->token}/accept")
        ->assertForbidden();

    expect($org->fresh()->roleOf($wrongUser))->toBeNull();
});

it('allows any authenticated user to accept a null-email (shareable link) invite', function () {
    $org = Organization::factory()->create();
    $invite = Invite::factory()->for($org)->create(['email' => null, 'role' => 'USER', 'expires_at' => now()->addDay()]);
    $anyUser = User::factory()->create();

    $this->actingAs($anyUser)->postJson("/api/invites/{$invite->token}/accept")->assertOk();

    expect($org->fresh()->roleOf($anyUser))->toBe(OrganizationRole::USER);
});

it('makes invites single-use: accepting twice fails the second time', function () {
    $org = Organization::factory()->create();
    $invite = Invite::factory()->for($org)->create(['email' => null, 'role' => 'USER', 'expires_at' => now()->addDay()]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson("/api/invites/{$invite->token}/accept")->assertOk();

    $otherOrg = Organization::factory()->create();
    $otherUser = User::factory()->create();
    actingAsMember($otherOrg, 'ADMIN');

    $this->actingAs($otherUser)->postJson("/api/invites/{$invite->token}/accept")
        ->assertStatus(422);
});

it('mints at most one membership when several accepts genuinely race for the same token', function () {
    // Sequential Pest/HTTP calls (like the single-use test above) can
    // never reproduce this: each call's transaction fully commits before
    // the next one starts, so there is never a moment where two accepts
    // are both actually in flight. Reproducing the TOCTOU requires real
    // OS-level concurrency -- see raceConcurrently() in tests/Pest.php.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    // Fixtures are created through the "race" connection (plain
    // autocommit, not wrapped in RefreshDatabase's open transaction on
    // the default connection) so they are immediately visible to the
    // forked child processes below, which reconnect from scratch and
    // cannot see anything still sitting in that uncommitted transaction.
    $owner = raceCreate(User::class, ['name' => 'Race Owner', 'email' => 'race-owner-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Race Co', 'slug' => 'race-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);
    $invite = raceCreate(Invite::class, [
        'organization_id' => $organization->id,
        'email' => null, // shareable link: any of the racing users may accept it.
        'token' => Str::random(64),
        'role' => OrganizationRole::USER,
        'expires_at' => now()->addDay(),
    ]);

    $racerCount = 5;
    $users = [];
    for ($i = 0; $i < $racerCount; $i++) {
        $users[] = raceCreate(User::class, ['name' => "Racer {$i}", 'email' => "race-user-{$i}-".Str::random(6).'@example.com', 'password' => 'irrelevant-hash']);
    }

    try {
        $racers = array_map(
            fn (User $user) => function () use ($invite, $user) {
                app(InviteService::class)->accept($invite->token, $user);
            },
            $users
        );

        $results = raceConcurrently($racers);

        // Exactly one of the N genuinely-concurrent accepts may win; the
        // old code (read outside the transaction) could let more than one
        // through. All the losers must fail with 422 (already accepted),
        // never a 500 -- a crash there would mean the race was resolved
        // by something ungoverned (e.g. a raw constraint violation)
        // rather than by InviteService's own guard.
        $succeeded = array_filter($results, fn ($r) => $r === 'ok');
        $failed = array_filter($results, fn ($r) => $r !== 'ok');

        expect($succeeded)->toHaveCount(1);
        foreach ($failed as $result) {
            expect($result)->toBe('fail:422');
        }

        $memberships = DB::connection('race')->table('organization_user')
            ->where('organization_id', $organization->id)
            ->get();

        expect($memberships)->toHaveCount(1);
    } finally {
        DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
        DB::connection('race')->table('invites')->where('id', $invite->id)->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->whereIn('id', [$owner->id, ...array_map(fn ($u) => $u->id, $users)])->delete();
    }
});

it('does not leak another organization\'s invites in InviteFactory-adjacent listing queries', function () {
    // Guards against a regression where invite listing/creation forgets to
    // scope by organization_id and could accept/list a token belonging to
    // a different tenant's invite via a bare Invite::where(...).
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    Invite::factory()->for($theirs)->create(['email' => 'theirs@example.com']);
    actingAsMember($mine, 'ADMIN');

    expect(Invite::forOrganization($mine)->count())->toBe(0);
});
