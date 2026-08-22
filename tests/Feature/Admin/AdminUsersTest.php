<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/*
 * The admin users list (Task 1.7). Impersonation shipped in Task 10 with
 * no way to choose whom to impersonate — a superadmin had to already know
 * a user id and POST to the endpoint by hand. This is the list that makes
 * the feature reachable.
 *
 * Instance-level, not tenant-level: there is no `organization` middleware
 * on the route and no organization scope on the query, because a
 * superadmin picking someone to impersonate is by definition looking
 * outside their own workspace. `superadmin` is the only gate, and it
 * reads the global `users.is_superadmin` column — never the per-tenant
 * OrganizationRole::SUPERADMIN, which is a different thing entirely.
 */

it('forbids a non-superadmin from listing users', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/api/admin/users')->assertForbidden();
});

it('requires a session at all', function () {
    $this->getJson('/api/admin/users')->assertUnauthorized();
});

it('lists users with the fields the admin screen needs', function () {
    $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    $organization = Organization::factory()->create(['name' => 'Newsroom']);
    $member = User::factory()->create(['name' => 'Dana Levin', 'email' => 'dana@example.test']);
    $organization->addMember($member, OrganizationRole::USER);

    $response = $this->getJson('/api/admin/users')->assertOk();

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $response->json('data');
    $matches = array_values(array_filter($rows, fn (array $row) => $row['email'] === 'dana@example.test'));

    expect($matches)->toHaveCount(1);

    $row = $matches[0];

    expect($row['name'])->toBe('Dana Levin')
        // The flag is the whole point of the screen's own gate, so the
        // list has to say who else holds it.
        ->and($row['is_superadmin'])->toBeFalse()
        ->and($row['organizations'])->toBe(['Newsroom']);
});

it('finds a user by name or by email', function () {
    $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    User::factory()->create(['name' => 'Dana Levin', 'email' => 'dana@example.test']);
    User::factory()->create(['name' => 'Sam Okoro', 'email' => 'sam@example.test']);

    /** @var array<int, array<string, mixed>> $byName */
    $byName = $this->getJson('/api/admin/users?search=Dana')->assertOk()->json('data');
    expect(array_column($byName, 'email'))->toBe(['dana@example.test']);

    /** @var array<int, array<string, mixed>> $byEmail */
    $byEmail = $this->getJson('/api/admin/users?search=sam@example')->assertOk()->json('data');
    expect(array_column($byEmail, 'email'))->toBe(['sam@example.test']);
});

it('treats ILIKE wildcards in the search term as literal characters', function () {
    $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    User::factory()->create(['name' => 'Dana Levin']);
    $literal = User::factory()->create(['name' => '100% cotton']);

    // Unescaped, '%' would match every row in the table — the search box
    // would silently stop filtering exactly when someone typed a
    // punctuation-heavy name.
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $this->getJson('/api/admin/users?search=100%25')->assertOk()->json('data');

    expect(array_column($rows, 'id'))->toBe([$literal->id]);
});

it('paginates rather than returning every user on one page', function () {
    $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    User::factory()->count(30)->create();

    $response = $this->getJson('/api/admin/users')->assertOk();

    expect($response->json('meta.per_page'))->toBe(25)
        ->and($response->json('data'))->toHaveCount(25)
        ->and($response->json('meta.last_page'))->toBeGreaterThan(1);
});
