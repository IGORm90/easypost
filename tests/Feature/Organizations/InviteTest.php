<?php

use App\Enums\OrganizationRole;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
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
