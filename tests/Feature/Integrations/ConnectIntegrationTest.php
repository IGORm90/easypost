<?php

use App\Models\Integration;
use App\Models\Organization;
use App\Services\Integrations\IntegrationService;
use App\Social\Dto\AuthResultDto;
use Illuminate\Support\Facades\DB;

/*
 * Task 12: `integrations` schema and IntegrationService.
 *
 * The brief's second scenario ("never exposes tokens through the api
 * resource") is deliberately NOT reproduced here: it requires
 * GET /api/integrations, which does not exist until Task 13. Its intent —
 * tokens must never leave the application unredacted — is instead covered
 * directly at the model level (`toArray()` below), which is the layer any
 * future API resource would be built on top of anyway.
 */

it('encrypts tokens at rest', function () {
    $integration = Integration::factory()->create(['token' => 'plain-secret']);

    $raw = DB::table('integrations')->where('id', $integration->id)->value('token');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toContain('plain-secret')
        ->and($integration->fresh()->token)->toBe('plain-secret');
});

it('never exposes tokens through toArray, the layer any api resource is built on', function () {
    $integration = Integration::factory()->create([
        'token' => 'plain-secret',
        'refresh_token' => 'plain-refresh-secret',
    ]);

    $array = $integration->fresh()->toArray();

    expect($array)->not->toHaveKey('token')
        ->and($array)->not->toHaveKey('refresh_token')
        ->and(json_encode($array))->not->toContain('plain-secret')
        ->and(json_encode($array))->not->toContain('plain-refresh-secret');
});

it('updates an existing integration on reconnect instead of duplicating', function () {
    $org = Organization::factory()->create();
    $svc = app(IntegrationService::class);

    $svc->connect($org, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-1'));
    $svc->connect($org, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-2'));

    expect(Integration::withoutGlobalScope('organization')->count())->toBe(1)
        ->and(Integration::withoutGlobalScope('organization')->first()->token)->toBe('tok-2');
});

it('clears refresh_needed on reconnect', function () {
    $org = Organization::factory()->create();
    $svc = app(IntegrationService::class);

    $integration = $svc->connect($org, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-1'));
    $integration->update(['refresh_needed' => true]);

    $reconnected = $svc->connect($org, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-2'));

    expect($reconnected->id)->toBe($integration->id)
        ->and($reconnected->refresh_needed)->toBeFalse();
});

it('does not create a duplicate live row across separate provider accounts or organizations', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $svc = app(IntegrationService::class);

    $svc->connect($orgA, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-1'));
    $svc->connect($orgA, 'telegram', new AuthResultDto('acc-2', 'Other chan', 'tok-2'));
    $svc->connect($orgB, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-3'));

    expect(Integration::withoutGlobalScope('organization')->count())->toBe(3);
});

/*
 * Security: cross-tenant edge of the upsert. Organization A connects and
 * then soft-deletes a channel; Organization B later connects "the same"
 * provider account id. Because IntegrationService::connect() scopes its
 * withTrashed() lookup by organization_id BEFORE anything else, it must
 * be structurally impossible for B's connect() to find, restore, or
 * overwrite A's soft-deleted row — B must get its own fresh row, and A's
 * row must remain exactly as it was left (still deleted, still carrying
 * A's data).
 */
it("does not resurrect another organization's soft-deleted integration on reconnect", function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $svc = app(IntegrationService::class);

    $forA = $svc->connect($orgA, 'telegram', new AuthResultDto('acc-shared', "A's channel", 'tok-a'));
    $svc->softDelete($forA);

    $forB = $svc->connect($orgB, 'telegram', new AuthResultDto('acc-shared', "B's channel", 'tok-b'));

    expect($forB->id)->not->toBe($forA->id)
        ->and($forB->organization_id)->toBe($orgB->id)
        ->and($forB->token)->toBe('tok-b')
        ->and(Integration::withoutGlobalScope('organization')->withTrashed()->count())->toBe(2);

    $aRow = Integration::withoutGlobalScope('organization')->withTrashed()->findOrFail($forA->id);
    expect($aRow->organization_id)->toBe($orgA->id)
        ->and($aRow->deleted_at)->not->toBeNull()
        ->and($aRow->name)->toBe("A's channel")
        ->and($aRow->token)->toBe('tok-a');
});

/*
 * Within the SAME organization, reconnecting after a soft delete is a
 * legitimate "I disconnected this by mistake, reconnect it" flow: it
 * restores and updates the existing row rather than leaving a
 * permanently-orphaned soft-deleted row behind plus a disconnected new
 * one.
 */
it('restores its own soft-deleted integration on reconnect, without duplicating', function () {
    $org = Organization::factory()->create();
    $svc = app(IntegrationService::class);

    $original = $svc->connect($org, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-1'));
    $svc->softDelete($original);

    $reconnected = $svc->connect($org, 'telegram', new AuthResultDto('acc-1', 'Chan', 'tok-2'));

    expect($reconnected->id)->toBe($original->id)
        ->and($reconnected->deleted_at)->toBeNull()
        ->and($reconnected->token)->toBe('tok-2')
        ->and(Integration::withoutGlobalScope('organization')->withTrashed()->count())->toBe(1);
});

it('disables and re-enables an integration', function () {
    $integration = Integration::factory()->create();
    $svc = app(IntegrationService::class);

    $svc->disable($integration);
    expect($integration->fresh()->disabled)->toBeTrue();

    $svc->enable($integration);
    expect($integration->fresh()->disabled)->toBeFalse();
});

it('soft-deletes an integration', function () {
    $integration = Integration::factory()->create();
    $svc = app(IntegrationService::class);

    $svc->softDelete($integration);

    expect(Integration::withoutGlobalScope('organization')->find($integration->id))->toBeNull()
        ->and(Integration::withoutGlobalScope('organization')->withTrashed()->find($integration->id)->deleted_at)->not->toBeNull();
});
