<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

function actingAsMember(Organization $org, string $role = 'ADMIN'): User
{
    $user = User::factory()->create();
    $org->members()->attach($user, ['id' => (string) Str::ulid(), 'role' => $role]);
    test()->actingAs($user);
    test()->withHeader('X-Organization', $org->id);

    return $user;
}
