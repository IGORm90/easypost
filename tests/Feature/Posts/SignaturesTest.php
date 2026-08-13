<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Signature;
use App\Models\User;
use App\Services\Posts\SignatureService;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Task 23: GET|POST|PUT|DELETE /api/signatures. At most one signature per
 * organization may be the default — setting a new default clears the
 * previous one. See the signatures migration (Task 18) for the partial
 * unique index (`signatures_one_default_per_org`) that backs this
 * invariant at the database layer regardless of what the application
 * code does.
 */

it('creates, lists, updates and deletes a signature', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $create = $this->postJson('/api/signatures', ['content' => 'Best, the team', 'is_default' => false]);
    $create->assertCreated();
    $id = $create->json('id');

    $index = $this->getJson('/api/signatures');
    $index->assertOk();
    expect(collect((array) $index->json())->pluck('id'))->toContain($id);

    $update = $this->putJson("/api/signatures/{$id}", ['content' => 'Cheers, the team', 'is_default' => false]);
    $update->assertOk();
    expect($update->json('content'))->toBe('Cheers, the team');

    $destroy = $this->deleteJson("/api/signatures/{$id}");
    $destroy->assertNoContent();
    expect(Signature::query()->find($id))->toBeNull();
});

it('rejects signature content over the length limit', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $response = $this->postJson('/api/signatures', [
        'content' => str_repeat('a', 1001),
        'is_default' => false,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['content']);
});

it('clears the previous default when a new signature is marked default', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $first = Signature::factory()->for($org)->default()->create();

    $create = $this->postJson('/api/signatures', ['content' => 'New default', 'is_default' => true]);
    $create->assertCreated();

    expect($first->fresh()->is_default)->toBeFalse();
    expect(Signature::query()->find($create->json('id'))->is_default)->toBeTrue();
    expect(Signature::query()->where('organization_id', $org->id)->where('is_default', true)->count())->toBe(1);
});

it('clears the previous default when an existing signature is updated to become the default', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $first = Signature::factory()->for($org)->default()->create();
    $second = Signature::factory()->for($org)->create();

    $update = $this->putJson("/api/signatures/{$second->id}", ['content' => $second->content, 'is_default' => true]);
    $update->assertOk();

    expect($first->fresh()->is_default)->toBeFalse();
    expect($second->fresh()->is_default)->toBeTrue();
});

it('never leaves two defaults (or zero) when two default-signature writes genuinely race', function () {
    // Sequential Pest/HTTP calls can never reproduce a TOCTOU race here —
    // each call's transaction fully commits before the next one starts.
    // See raceConcurrently() in tests/Pest.php and RoleTest's identical
    // rationale for the last-SUPERADMIN guard.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Sig Race Owner', 'email' => 'sig-race-owner-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Sig Race Co', 'slug' => 'sig-race-co-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id]);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);

    $organizationId = $organization->id;
    $racerCount = 5;

    try {
        $racers = array_map(
            fn (int $i) => function () use ($organizationId, $i) {
                $organization = Organization::findOrFail($organizationId);
                OrganizationContext::set($organization);

                app(SignatureService::class)->create([
                    'content' => "Race signature {$i}",
                    'is_default' => true,
                ]);
            },
            range(1, $racerCount),
        );

        $results = raceConcurrently($racers);

        // Locking (not the unique index alone) means every concurrent
        // write should succeed — the index is a last-resort guard, not
        // the mechanism the application relies on to serialize these
        // writes. See SignatureService::clearDefault()'s docblock.
        foreach ($results as $result) {
            expect($result)->toBe('ok');
        }

        $defaultCount = DB::connection('race')->table('signatures')
            ->where('organization_id', $organizationId)
            ->where('is_default', true)
            ->count();

        expect($defaultCount)->toBe(1);

        $totalCount = DB::connection('race')->table('signatures')
            ->where('organization_id', $organizationId)
            ->count();

        expect($totalCount)->toBe($racerCount);
    } finally {
        DB::connection('race')->table('signatures')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organization_user')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organizations')->where('id', $organizationId)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
        OrganizationContext::clear();
    }
});
