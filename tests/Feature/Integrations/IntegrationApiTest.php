<?php

use App\Models\Integration;
use App\Models\Organization;
use App\Services\Integrations\IntegrationService;
use App\Social\Dto\AuthResultDto;
use Tests\Support\NoOptionsFakeProvider;

/*
 * Task 13: the OAuth-connect HTTP surface (GET /api/providers,
 * GET /api/integrations, POST auth-url/callback, PUT/disable/enable/DELETE
 * /api/integrations/{id}, GET /api/integrations/{id}/options/{option}).
 * Everything here runs against the 'fake' provider (Tests\Support\FakeProvider,
 * wired in Tests\TestCase::setUp()) — no real network provider exists yet
 * (Tasks 14-15).
 */

// --- GET /api/providers -----------------------------------------------

it('lists registered providers with capabilities and auth_type', function () {
    $user = actingAsMember(Organization::factory()->create());

    $response = $this->getJson('/api/providers');

    $response->assertOk();
    $fake = collect((array) $response->json())->firstWhere('identifier', 'fake');
    expect($fake)->not->toBeNull()
        ->and($fake['name'])->toBe('Fake')
        ->and($fake['auth_type'])->toBe('oauth')
        ->and($fake['capabilities']['maxCharacters'])->toBe(4096);
});

// --- GET /api/integrations ----------------------------------------------

it('lists only the current organization\'s integrations', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    Integration::factory()->create(['organization_id' => $orgA->id, 'name' => 'A channel']);
    Integration::factory()->create(['organization_id' => $orgB->id, 'name' => 'B channel']);

    actingAsMember($orgA);
    $response = $this->getJson('/api/integrations');

    $response->assertOk();
    expect($response->json())->toHaveCount(1)
        ->and($response->json()[0]['name'])->toBe('A channel');
});

it('never exposes token or refresh_token through GET /api/integrations', function () {
    $org = Organization::factory()->create();
    Integration::factory()->create([
        'organization_id' => $org->id,
        'token' => 'super-secret-token',
        'refresh_token' => 'super-secret-refresh',
    ]);

    actingAsMember($org);
    $response = $this->getJson('/api/integrations');

    $response->assertOk();
    expect($response->json()[0])->not->toHaveKey('token')
        ->and($response->json()[0])->not->toHaveKey('refresh_token')
        ->and($response->getContent())->not->toContain('super-secret-token')
        ->and($response->getContent())->not->toContain('super-secret-refresh');
});

it('excludes soft-deleted integrations from the list', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id]);
    app(IntegrationService::class)->softDelete($integration);

    actingAsMember($org);
    $response = $this->getJson('/api/integrations');

    $response->assertOk()->assertJsonCount(0);
});

// --- POST /api/integrations/{provider}/auth-url --------------------------

it('generates an auth url and a state for an admin', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    $response = $this->postJson('/api/integrations/fake/auth-url');

    $response->assertOk()->assertJsonStructure(['url', 'state']);
    expect($response->json('url'))->toContain('state='.$response->json('state'));
});

it('rejects auth-url for a non-admin member', function () {
    $org = Organization::factory()->create();
    actingAsMember($org, 'USER');

    $this->postJson('/api/integrations/fake/auth-url')->assertForbidden();
});

it('404s auth-url for an unknown provider identifier, not a leaked exception', function () {
    actingAsMember(Organization::factory()->create());

    $response = $this->postJson('/api/integrations/does-not-exist/auth-url');

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('UnknownProviderException');
});

// --- POST /api/integrations/{provider}/callback: state lifecycle ---------

/**
 * @return array{state: string, url: string}
 */
function startFakeConnectFlow(Organization $org): array
{
    actingAsMember($org, 'ADMIN');
    $response = test()->postJson('/api/integrations/fake/auth-url');

    return ['state' => $response->json('state'), 'url' => $response->json('url')];
}

it('completes a connect flow and creates a channel, without needing X-Organization on the callback', function () {
    $org = Organization::factory()->create();
    $flow = startFakeConnectFlow($org);

    $this->withoutHeader('X-Organization');
    $response = $this->postJson('/api/integrations/fake/callback', [
        'state' => $flow['state'],
        'internal_id' => 'acc-1',
        'name' => 'My Fake Channel',
    ]);

    $response->assertCreated();
    expect($response->json('name'))->toBe('My Fake Channel')
        ->and(Integration::withoutGlobalScope('organization')->count())->toBe(1)
        ->and(Integration::withoutGlobalScope('organization')->first()->organization_id)->toBe($org->id);
});

it('404s callback for an unknown provider identifier', function () {
    actingAsMember(Organization::factory()->create());

    $this->postJson('/api/integrations/does-not-exist/callback', ['state' => 'whatever'])
        ->assertNotFound();
});

it('rejects a callback with no state', function () {
    actingAsMember(Organization::factory()->create());

    $this->postJson('/api/integrations/fake/callback', [])
        ->assertUnprocessable();
});

it('rejects a callback with an unknown/forged state', function () {
    actingAsMember(Organization::factory()->create());

    $this->postJson('/api/integrations/fake/callback', ['state' => str_repeat('x', 64)])
        ->assertStatus(422);
    expect(Integration::withoutGlobalScope('organization')->count())->toBe(0);
});

it("rejects a callback presenting another user's state within the same organization", function () {
    $org = Organization::factory()->create();
    $flow = startFakeConnectFlow($org); // minted for the first ADMIN created by actingAsMember()

    actingAsMember($org, 'ADMIN'); // a second, different ADMIN of the same org

    $response = $this->postJson('/api/integrations/fake/callback', ['state' => $flow['state']]);

    $response->assertStatus(422);
    expect(Integration::withoutGlobalScope('organization')->count())->toBe(0);
});

it("rejects a callback presenting another organization's state (minted for a user in a different org)", function () {
    $orgA = Organization::factory()->create();
    $flow = startFakeConnectFlow($orgA);

    $orgC = Organization::factory()->create();
    actingAsMember($orgC, 'ADMIN'); // an unrelated user, member only of orgC

    $response = $this->postJson('/api/integrations/fake/callback', ['state' => $flow['state']]);

    $response->assertStatus(422);
    expect(Integration::withoutGlobalScope('organization')->count())->toBe(0);
});

it('rejects an expired state (older than 10 minutes)', function () {
    $org = Organization::factory()->create();
    $flow = startFakeConnectFlow($org);

    $this->travel(11)->minutes();

    $this->postJson('/api/integrations/fake/callback', ['state' => $flow['state']])
        ->assertStatus(422);
});

it('rejects reusing a state a second time (single-use)', function () {
    $org = Organization::factory()->create();
    $flow = startFakeConnectFlow($org);

    $this->postJson('/api/integrations/fake/callback', ['state' => $flow['state'], 'internal_id' => 'acc-1'])
        ->assertCreated();

    $second = $this->postJson('/api/integrations/fake/callback', ['state' => $flow['state'], 'internal_id' => 'acc-2']);

    $second->assertStatus(422);
    expect(Integration::withoutGlobalScope('organization')->count())->toBe(1);
});

/*
 * Concurrent-connect race (Task 12's partial unique index). Deterministic
 * simulation rather than genuine multi-process concurrency: a test-only
 * IntegrationService subclass forces the FIRST upsert lookup (the one
 * connect() runs before its save()) to miss even though a live row for
 * the same key already exists — reproducing exactly the window a real
 * race would open between two concurrent callers' lookups and inserts.
 * The resulting INSERT then genuinely collides with the real database's
 * partial unique index (no mocking of the exception itself), proving
 * connect() recovers by re-fetching instead of letting a raw
 * QueryException reach the OAuth callback.
 */
it('recovers from a lost race on the unique index instead of 500ing', function () {
    $org = Organization::factory()->create();
    $existing = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'fake',
        'internal_id' => 'raced-account',
    ]);

    $racy = new class extends IntegrationService
    {
        private int $calls = 0;

        protected function findUpsertTarget(Organization $org, string $providerIdentifier, string $internalId): ?Integration
        {
            $this->calls++;

            return $this->calls === 1 ? null : parent::findUpsertTarget($org, $providerIdentifier, $internalId);
        }
    };

    $result = $racy->connect($org, 'fake', new AuthResultDto('raced-account', 'Raced Channel', 'tok-raced'));

    expect($result->id)->toBe($existing->id)
        ->and(Integration::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(1);
});

// --- PUT /api/integrations/{id} -------------------------------------------

it('updates name/group/customer/settings and replaces time_slots', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id]);
    actingAsMember($org, 'ADMIN');

    $response = $this->putJson("/api/integrations/{$integration->id}", [
        'name' => 'Renamed',
        'group_name' => 'Group 1',
        'customer_name' => 'Acme',
        'settings' => ['foo' => 'bar'],
        'time_slots' => [0, 60, 10079],
    ]);

    $response->assertOk();
    expect($response->json('name'))->toBe('Renamed')
        ->and($response->json('group_name'))->toBe('Group 1')
        ->and($response->json('customer_name'))->toBe('Acme')
        ->and($response->json('settings'))->toBe(['foo' => 'bar'])
        ->and($response->json('time_slots'))->toEqualCanonicalizing([0, 60, 10079])
        ->and($integration->fresh()->timeSlots()->count())->toBe(3);
});

it('rejects a time slot outside one week and duplicate time slots', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id]);
    actingAsMember($org, 'ADMIN');

    $this->putJson("/api/integrations/{$integration->id}", ['time_slots' => [10080]])
        ->assertInvalid(['time_slots.0']);

    $this->putJson("/api/integrations/{$integration->id}", ['time_slots' => [-1]])
        ->assertInvalid(['time_slots.0']);

    $this->putJson("/api/integrations/{$integration->id}", ['time_slots' => [60, 60]])
        ->assertInvalid(['time_slots.0', 'time_slots.1']);
});

it("rejects updating another organization's integration as if it does not exist", function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreign = Integration::factory()->create(['organization_id' => $orgB->id]);
    actingAsMember($orgA, 'ADMIN');

    $response = $this->putJson("/api/integrations/{$foreign->id}", ['name' => 'Hijacked']);

    $response->assertNotFound();
    expect($foreign->fresh()->name)->not->toBe('Hijacked');
});

it('rejects update for a non-admin member with the same 403 shape as any other org, not existence-revealing', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id]);
    actingAsMember($org, 'USER');

    $this->putJson("/api/integrations/{$integration->id}", ['name' => 'Nope'])->assertForbidden();
});

// --- disable / enable / delete --------------------------------------------

it('disables and re-enables a channel', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id]);
    actingAsMember($org, 'ADMIN');

    $this->postJson("/api/integrations/{$integration->id}/disable")->assertOk();
    expect($integration->fresh()->disabled)->toBeTrue();

    $this->postJson("/api/integrations/{$integration->id}/enable")->assertOk();
    expect($integration->fresh()->disabled)->toBeFalse();
});

it("rejects disabling another organization's channel as not found", function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreign = Integration::factory()->create(['organization_id' => $orgB->id]);
    actingAsMember($orgA, 'ADMIN');

    $this->postJson("/api/integrations/{$foreign->id}/disable")->assertNotFound();
    expect($foreign->fresh()->disabled)->toBeFalse();
});

/*
 * disable/enable share findAuthorized() with every other {id}-scoped
 * action, but that's exactly why each one still needs its own direct
 * test: a copy-paste slip that broke only enable() would otherwise
 * survive a green suite indefinitely, since nothing else exercises it
 * independently of the "disables and re-enables" happy path above.
 */
it("rejects enabling another organization's channel as not found", function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreign = Integration::factory()->create(['organization_id' => $orgB->id, 'disabled' => true]);
    actingAsMember($orgA, 'ADMIN');

    $this->postJson("/api/integrations/{$foreign->id}/enable")->assertNotFound();
    expect($foreign->fresh()->disabled)->toBeTrue();
});

it('rejects enable for a non-admin member', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id, 'disabled' => true]);
    actingAsMember($org, 'USER');

    $this->postJson("/api/integrations/{$integration->id}/enable")->assertForbidden();
    expect($integration->fresh()->disabled)->toBeTrue();
});

it('soft-deletes a channel via DELETE and it disappears from the list', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id]);
    actingAsMember($org, 'ADMIN');

    $this->deleteJson("/api/integrations/{$integration->id}")->assertNoContent();

    expect($integration->fresh()->trashed())->toBeTrue();
    $this->getJson('/api/integrations')->assertJsonCount(0);
});

it("rejects deleting another organization's channel as not found, and it survives untouched", function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreign = Integration::factory()->create(['organization_id' => $orgB->id]);
    actingAsMember($orgA, 'ADMIN');

    $this->deleteJson("/api/integrations/{$foreign->id}")->assertNotFound();
    expect($foreign->fresh()->trashed())->toBeFalse();
});

it('404s a genuinely nonexistent id the same way as a cross-tenant one (not an existence oracle)', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreign = Integration::factory()->create(['organization_id' => $orgB->id]);
    actingAsMember($orgA, 'ADMIN');

    $missing = $this->deleteJson('/api/integrations/01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $crossTenant = $this->deleteJson("/api/integrations/{$foreign->id}");

    expect($missing->status())->toBe(404)->and($crossTenant->status())->toBe(404);
});

// --- GET /api/integrations/{id}/options/{option} -------------------------

it('delegates options to the provider generically', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id, 'provider_identifier' => 'fake']);
    actingAsMember($org, 'USER');

    $response = $this->getJson("/api/integrations/{$integration->id}/options/rooms");

    $response->assertOk();
    expect($response->json('options'))->toBe([
        ['value' => 'general', 'label' => 'General'],
        ['value' => 'random', 'label' => 'Random'],
    ]);
});

it('404s for an option name the provider does not recognise', function () {
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $org->id, 'provider_identifier' => 'fake']);
    actingAsMember($org, 'USER');

    $this->getJson("/api/integrations/{$integration->id}/options/not-a-real-option")->assertNotFound();
});

it('404s for a provider that has no options capability at all', function () {
    config()->set('social.providers.fake-no-options', NoOptionsFakeProvider::class);
    $org = Organization::factory()->create();
    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'fake-no-options',
    ]);
    actingAsMember($org, 'USER');

    $this->getJson("/api/integrations/{$integration->id}/options/anything")->assertNotFound();
});

it("rejects reading another organization's channel options as not found", function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $foreign = Integration::factory()->create(['organization_id' => $orgB->id, 'provider_identifier' => 'fake']);
    actingAsMember($orgA, 'USER');

    $this->getJson("/api/integrations/{$foreign->id}/options/rooms")->assertNotFound();
});
