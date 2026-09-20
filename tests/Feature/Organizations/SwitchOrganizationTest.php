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

it('creates a first organization owned by the caller as SUPERADMIN with a root JSON resource', function () {
    $user = User::factory()->create();
    expect($user->organizations()->count())->toBe(0);

    $response = $this->actingAs($user)
        ->postJson('/api/organizations', ['name' => 'Acme Inc'])
        ->assertCreated();

    $organization = Organization::findOrFail($response->json('id'));
    $response->assertExactJson([
        'id' => $organization->id,
        'name' => 'Acme Inc',
        'slug' => $organization->slug,
        'created_at' => $organization->created_at->toISOString(),
        'updated_at' => $organization->updated_at->toISOString(),
    ]);
    expect($organization->owner_id)->toBe($user->id);
    expect($organization->roleOf($user))->toBe(OrganizationRole::SUPERADMIN);
});

it('requires authentication to create an organization', function () {
    $this->postJson('/api/organizations', ['name' => 'Acme Inc'])
        ->assertUnauthorized();

    $this->assertDatabaseCount('organizations', 0);
    $this->assertDatabaseCount('organization_user', 0);
});

it('rejects invalid organization names without persisting a workspace', function (array $payload) {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/organizations', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $this->assertDatabaseCount('organizations', 0);
    $this->assertDatabaseCount('organization_user', 0);
})->with([
    'missing name' => [[]],
    'empty name' => [['name' => '']],
    'whitespace name' => [['name' => '   ']],
    'non-string name' => [['name' => ['Acme Inc']]],
    'name longer than 255 characters' => [['name' => str_repeat('a', 256)]],
]);

it('lets a USER create a separately owned organization without changing the existing membership', function () {
    $existing = Organization::factory()->create();
    $user = actingAsMember($existing, 'USER');

    $response = $this->postJson('/api/organizations', ['name' => 'My workspace'])
        ->assertCreated();

    $created = Organization::findOrFail($response->json('id'));
    expect($created->id)->not->toBe($existing->id);
    expect($created->owner_id)->toBe($user->id);
    expect($created->roleOf($user))->toBe(OrganizationRole::SUPERADMIN);
    expect($existing->fresh()->roleOf($user))->toBe(OrganizationRole::USER);
    expect($user->organizations()->count())->toBe(2);
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
