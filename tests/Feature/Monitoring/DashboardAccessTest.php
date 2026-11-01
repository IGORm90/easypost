<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * One gate, `viewMonitoring`, decides who may open the platform
 * dashboards (Horizon today; Pulse and Log Viewer reference the same gate
 * when they are installed). It is `users.is_superadmin` alone: the
 * dashboards show every organization's jobs and logs, so a role inside one
 * organization — including OrganizationRole::SUPERADMIN — must never be
 * enough. See "Администратор платформы" in GLOSSARY.md.
 *
 * Horizon's own default (`viewHorizon` gate, or "anyone when the app
 * environment is local") is deliberately not used; it would let everyone in
 * on a developer's machine and nobody in anywhere else regardless of role.
 * Horizon's `Authenticate` middleware also never requires a logged-in user
 * before consulting the callback, so the guest case is tested explicitly.
 */
it('grants the viewMonitoring gate to a platform admin only', function () {
    $platformAdmin = User::factory()->create(['is_superadmin' => true]);
    $ordinary = User::factory()->create(['is_superadmin' => false]);

    expect(Gate::forUser($platformAdmin)->allows('viewMonitoring'))->toBeTrue()
        ->and(Gate::forUser($ordinary)->allows('viewMonitoring'))->toBeFalse()
        ->and(Gate::allows('viewMonitoring'))->toBeFalse();
});

it('forbids an ordinary authenticated user from viewing horizon', function () {
    $user = User::factory()->create(['is_superadmin' => false]);

    $this->actingAs($user)
        ->get('/horizon/api/stats')
        ->assertForbidden();
});

it('forbids an organization SUPERADMIN who is not a platform admin', function () {
    $org = Organization::factory()->create();
    $user = actingAsMember($org, 'SUPERADMIN');

    // fresh(): the factory model never saw the column's DB default.
    expect($user->fresh()->is_superadmin)->toBeFalse();

    $this->get('/horizon/api/stats')->assertForbidden();
});

it('lets a platform admin view horizon', function () {
    $platformAdmin = User::factory()->create(['is_superadmin' => true]);

    $this->actingAs($platformAdmin)
        ->get('/horizon/api/stats')
        ->assertOk();
});

it('forbids an unauthenticated visitor from viewing horizon', function () {
    $this->get('/horizon/api/stats')
        ->assertForbidden();
});

/*
 * The SPA catch-all answers 200 with an empty shell for any path it does
 * not exclude, so an unreserved dashboard path renders a white screen
 * instead of a 404 (seen on /pulse before Pulse existed). The paths are
 * reserved now; whatever serves them later — the package or a 404 — is
 * never the Vue shell.
 */
it('does not serve the SPA shell on the monitoring dashboard paths', function (string $path) {
    $platformAdmin = User::factory()->create(['is_superadmin' => true]);

    $this->actingAs($platformAdmin)
        ->get($path)
        ->assertDontSee('<div id="app">', false);
})->with(['/pulse', '/log-viewer', '/horizon']);

it('still serves the SPA shell on paths that merely start with a reserved word', function (string $path) {
    $this->get($path)->assertOk()->assertSee('<div id="app">', false);
})->with(['/pulse-settings', '/log-viewers', '/horizons']);
