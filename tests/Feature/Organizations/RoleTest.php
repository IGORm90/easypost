<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

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

it('returns 404 when acting on a user who is not a member of the current organization', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');
    $stranger = User::factory()->create();

    $this->deleteJson("/api/organizations/current/members/{$stranger->id}")->assertNotFound();
});
