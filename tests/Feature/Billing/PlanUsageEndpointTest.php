<?php

use App\Models\Integration;
use App\Models\Organization;

/*
 * Task 36 built `PlanLimitService::usage()` and described it as being
 * "for the UI"; nothing exposed it, so a user could be told they had hit
 * a limit and had no way to see how close they were beforehand. Final
 * review, batch 4 (coverage and gates).
 */
it('reports the plan and the current usage of every limit', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    Integration::factory()->for($org)->count(2)->create(['provider_identifier' => 'fake']);

    $response = $this->getJson('/api/organizations/current/plan')->assertOk();

    expect($response->json('plan'))->toBe('free')
        ->and($response->json('usage.channels.current'))->toBe(2)
        // The maximum comes from config/plans.php, so this asserts only
        // that a ceiling is reported — pinning the number here would make
        // this test a second, divergent copy of that config.
        ->and($response->json('usage.channels.max'))->toBeInt()
        ->and($response->json('usage.posts_per_month.current'))->toBe(0)
        // The UTC calendar month the post count resets on, so a UI can
        // say when without guessing at a timezone.
        ->and($response->json('usage.posts_per_month.period'))->toBe(now()->utc()->format('Y-m'))
        ->and($response->json('usage.media_storage_mb.current'))->toBe(0);
});

it('reports usage for the requested organization, not whichever one exists', function () {
    // The whole answer is tenant-scoped; a wrong-organization reading
    // would be a quota leak AND a wrong number on the user's own screen.
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    Integration::factory()->for($theirs)->count(3)->create(['provider_identifier' => 'fake']);
    actingAsMember($mine);

    $response = $this->getJson('/api/organizations/current/plan')->assertOk();

    expect($response->json('usage.channels.current'))->toBe(0);
});

it('is readable by a plain member, not only an admin', function () {
    // Deliberate: how much of your own organization's quota is spent is
    // not privileged relative to the person it constrains.
    $org = Organization::factory()->create();
    actingAsMember($org, 'USER');

    $this->getJson('/api/organizations/current/plan')->assertOk();
});

it('requires an organization', function () {
    // 403, not 404/422: ResolveOrganization deliberately answers every
    // "not admitted" case identically — missing header, malformed header,
    // no membership — so the response never distinguishes an organization
    // that does not exist from one the caller may not see.
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->withHeader('X-Organization', '')
        ->getJson('/api/organizations/current/plan')
        ->assertForbidden();
});

it('401s an unauthenticated caller', function () {
    $this->getJson('/api/organizations/current/plan')->assertUnauthorized();
});
