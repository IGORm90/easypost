<?php

use App\Models\Invite;
use App\Models\Organization;

/*
 * See tests/Unit/OrganizationModelTest.php for why this file lives in
 * tests/Unit and hits the real (migrated) database directly.
 */
it('scopes invites to one organization via forOrganization', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $inviteA = Invite::factory()->for($orgA, 'organization')->create();
    $inviteB = Invite::factory()->for($orgB, 'organization')->create();

    $rows = Invite::forOrganization($orgA)->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->id)->toBe($inviteA->id);
    expect($rows->pluck('id'))->not->toContain($inviteB->id);
});

it('accepts an organization id string as well as an Organization instance', function () {
    $org = Organization::factory()->create();
    Invite::factory()->for($org, 'organization')->create();

    expect(Invite::forOrganization($org->id)->count())->toBe(1);
});
