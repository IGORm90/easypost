<?php

use App\Models\Organization;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/*
 * LOG_PLAN.md Phase 1.7: end to end through the real HTTP kernel — not
 * the middleware classes directly — so a wiring regression in
 * bootstrap/app.php (wrong group, wrong order) fails this test even
 * though the unit-level middleware tests would still pass.
 */
afterEach(function () {
    Context::flush();
});

it('echoes a generated X-Request-Id on the response when the client sends none', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $response = $this->getJson('/api/organizations');

    $response->assertOk();
    $id = $response->headers->get('X-Request-Id');
    expect($id)->toBeString();
    expect($id)->not->toBe('');
});

it('honours a client-supplied X-Request-Id end to end', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $response = $this->withHeader('X-Request-Id', 'e2e-test-id-456')
        ->getJson('/api/organizations');

    $response->assertOk();
    expect($response->headers->get('X-Request-Id'))->toBe('e2e-test-id-456');
});

it('carries request_id, organization_id and user_id once the organization middleware has run', function () {
    $org = Organization::factory()->create();
    $user = actingAsMember($org);

    $response = $this->withHeader('X-Request-Id', 'e2e-context-id')
        ->getJson('/api/organizations/current/plan');

    $response->assertOk();

    // The org/user checks only prove SOMETHING was written after
    // ResolveOrganization ran — this proves it landed in the actual log
    // record a shipper would see, not merely in Context.
    $path = tempnam(sys_get_temp_dir(), 'easypost-log-');
    register_shutdown_function(static fn () => @unlink($path));
    config(['logging.channels.app.with.stream' => $path]);
    Log::channel('app')->info('test.probe');

    $record = json_decode(trim((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

    expect($record['extra']['request_id'])->toBe('e2e-context-id');
    expect($record['extra']['organization_id'])->toBe($org->id);
    expect($record['extra']['user_id'])->toBe($user->id);
});
