<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

function actingAsMember(Organization $org, string $role = 'ADMIN'): User
{
    $user = User::factory()->create();
    // Organization::addMember(), not members()->attach(): attach() inserts
    // through the query builder and never fills organization_user.id (no
    // DB default, HasUlids' creating hook never fires for it) — see
    // OrganizationUser's docblock.
    $org->addMember($user, OrganizationRole::from($role));
    test()->actingAs($user);
    test()->withHeader('X-Organization', $org->id);

    return $user;
}
