<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Integrations\Exceptions\InvalidOAuthStateException;
use App\Services\Integrations\OAuthStateService;

/*
 * Final review, batch 4 (tenancy minors). Starting a connect flow
 * requires `role:ADMIN` on `integrations/{provider}/auth-url`; completing
 * one carries no role middleware at all, because `callback` has no
 * X-Organization header to resolve — the organization comes from the
 * `state` alone. `OAuthStateService::consume()` was the only
 * authorization left on that path and it re-checked MEMBERSHIP but not
 * ROLE, so within the state's 10-minute TTL a user demoted from ADMIN
 * could still finish the connect and attach a live credential to the
 * organization.
 *
 * routes/api.php states the intended rule outright — "starting a connect
 * flow carries the same authorization bar as completing one (see
 * IntegrationPolicy::manage())" — so this is the code catching up with
 * what the routing already promised.
 */
it('refuses to complete a connect for a user demoted below ADMIN since the state was issued', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->addMember($user, OrganizationRole::ADMIN);

    $state = app(OAuthStateService::class)->generate($organization, $user);

    // The window this test exists for: the demotion lands after the user
    // left for the provider and before they came back.
    OrganizationUser::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $user->id)
        ->update(['role' => OrganizationRole::USER]);

    expect(fn () => app(OAuthStateService::class)->consume($state, $user))
        ->toThrow(InvalidOAuthStateException::class);
});

it('still completes a connect for a user who kept ADMIN', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->addMember($user, OrganizationRole::ADMIN);

    $state = app(OAuthStateService::class)->generate($organization, $user);

    expect(app(OAuthStateService::class)->consume($state, $user)->organization->id)->toBe($organization->id);
});

it('completes a connect for a SUPERADMIN, since roles are hierarchical', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->addMember($user, OrganizationRole::SUPERADMIN);

    $state = app(OAuthStateService::class)->generate($organization, $user);

    expect(app(OAuthStateService::class)->consume($state, $user)->organization->id)->toBe($organization->id);
});
