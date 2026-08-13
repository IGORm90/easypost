<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

it('lists only the organizations the authenticated user belongs to', function () {
    $mine = Organization::factory()->create();
    $other = Organization::factory()->create();
    actingAsMember($mine);
    $other->addMember(User::factory()->create(), OrganizationRole::ADMIN);

    $response = $this->getJson('/api/organizations')->assertOk();

    $ids = collect((array) $response->json())->pluck('id');
    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($other->id);
});

it('creates a new organization owned by the caller as SUPERADMIN', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/organizations', ['name' => 'Acme Inc'])
        ->assertCreated();

    $organization = Organization::find($response->json('id'));
    expect($organization)->not->toBeNull();
    expect($organization->roleOf($user))->toBe(OrganizationRole::SUPERADMIN);
});

it('updates the current organization settings as an ADMIN', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $this->putJson('/api/organizations/current', [
        'name' => 'Renamed Org',
    ])->assertOk()
        ->assertJsonPath('name', 'Renamed Org');

    expect($org->fresh()->name)->toBe('Renamed Org');
});

it('ignores unknown fields such as shortlink_provider (not built yet, no defined value space)', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $this->putJson('/api/organizations/current', [
        'name' => 'Still Renamed',
        'shortlink_provider' => 'bitly',
    ])->assertOk()
        ->assertJsonMissingPath('shortlink_provider');

    expect($org->fresh()->name)->toBe('Still Renamed');
});

it('forbids a USER from updating organization settings', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'USER');

    $this->putJson('/api/organizations/current', ['name' => 'Hostile Rename'])
        ->assertForbidden();
});

it('blocks access to another organization', function () {
    $mine = Organization::factory()->create();
    $other = Organization::factory()->create();
    actingAsMember($mine);

    $this->withHeader('X-Organization', $other->id)->getJson('/api/organizations/current/members')
        ->assertForbidden();
});
