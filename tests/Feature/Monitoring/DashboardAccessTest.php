<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\LogFolder;

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
})->with(['/pulse', '/horizon']);

it('still serves the SPA shell on paths that merely start with a reserved word', function (string $path) {
    $this->get($path)->assertOk()->assertSee('<div id="app">', false);
})->with(['/pulse-settings', '/log-viewers', '/horizons']);

/*
 * Log Viewer ships its own rule: with no `viewLogViewer` gate and no auth
 * callback it lets EVERYONE in outside production, and 403s everyone in
 * production. Neither is acceptable, so it is wired to `viewMonitoring`
 * through LogViewer::auth(). The page and its JSON API are separate route
 * groups with separate middleware, so both are covered.
 */
it('forbids everyone but a platform admin from the log viewer page and api', function (string $path) {
    $this->get($path)->assertForbidden();

    $this->actingAs(User::factory()->create(['is_superadmin' => false]))
        ->get($path)->assertForbidden();

    $org = Organization::factory()->create();
    actingAsMember($org, 'SUPERADMIN');
    $this->get($path)->assertForbidden();
})->with(['/log-viewer', '/log-viewer/api/files']);

it('lets a platform admin open the log viewer page and api', function (string $path) {
    $this->actingAs(User::factory()->create(['is_superadmin' => true]))
        ->get($path)
        ->assertOk();
})->with(['/log-viewer', '/log-viewer/api/files']);

it('serves the real Log Viewer page, not the SPA shell, to a platform admin', function () {
    $this->actingAs(User::factory()->create(['is_superadmin' => true]))
        ->get('/log-viewer')
        ->assertOk()
        ->assertSee('id="log-viewer"', false)
        ->assertDontSee('<div id="app">', false);
});

/*
 * Logs are the only record of an incident now that nothing ships them
 * elsewhere, and Log Viewer ships with one-click file and folder deletion
 * enabled for anyone who may view it. Viewing is for the platform admin;
 * deleting is for nobody — rotation (LOG_DAILY_DAYS) is the only thing that
 * removes a file.
 */
it('lets nobody delete log files through the log viewer', function (string $ability) {
    $platformAdmin = User::factory()->create(['is_superadmin' => true]);

    // The package's own default gates type-hint their subject, so a real
    // LogFile / LogFolder is passed; the path is never read from disk.
    $subject = $ability === 'deleteLogFile'
        ? new LogFile('/nonexistent/viewer-2000-01-01.log')
        : new LogFolder('/nonexistent', []);

    expect(Gate::forUser($platformAdmin)->allows($ability, [$subject]))->toBeFalse();
})->with(['deleteLogFile', 'deleteLogFolder']);

/*
 * Pulse defines `viewPulse` as "allowed when APP_ENV is local" unless the
 * app defines it first, and this stack's environment is always `local` —
 * so by default every guest could open /pulse. PULSE_ENABLED is false under
 * phpunit, so the route is not registered here and the gate is asserted
 * directly; the HTTP side is a manual check (docs/ACCEPTANCE.md).
 */
it('grants the viewPulse gate to a platform admin only', function () {
    $platformAdmin = User::factory()->create(['is_superadmin' => true]);
    $ordinary = User::factory()->create(['is_superadmin' => false]);

    expect(Gate::forUser($platformAdmin)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($ordinary)->allows('viewPulse'))->toBeFalse()
        ->and(Gate::allows('viewPulse'))->toBeFalse();
});
