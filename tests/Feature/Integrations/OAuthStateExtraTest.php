<?php

use App\Models\Integration;
use App\Models\Organization;
use App\Services\Integrations\Exceptions\InvalidOAuthStateException;
use App\Services\Integrations\OAuthStateService;

/*
 * Block 5.0.2: whatever a provider needs to remember between
 * `generateAuthUrl()` and `authenticate()` is stored WITH the OAuth
 * state, server side.
 *
 * Until Block 5 the only such value was Mastodon's instance host, and
 * round-tripping it through the browser (sessionStorage, then back in the
 * callback body) was harmless. PKCE changes that: four of the eight new
 * networks put a `code_verifier` there, and a verifier the browser holds
 * — and can therefore be read or replaced — is half of the proof that the
 * authorization code belongs to this connect attempt. The state entry
 * already exists, is already single-use, and is already the thing the
 * callback is trusted by; the verifier belongs in it.
 */

it('hands back the extra a provider stored when the state was minted', function () {
    $org = Organization::factory()->create();
    $user = actingAsMember($org, 'ADMIN');

    $service = app(OAuthStateService::class);
    $state = $service->generate($org, $user);
    $service->remember($state, ['code_verifier' => 'v-123', 'instance' => 'example.social']);

    $result = $service->consume($state, $user);

    expect($result->organization->id)->toBe($org->id);
    expect($result->extra)->toBe(['code_verifier' => 'v-123', 'instance' => 'example.social']);
});

it('reports no extra for a state nothing was remembered against', function () {
    $org = Organization::factory()->create();
    $user = actingAsMember($org, 'ADMIN');

    $service = app(OAuthStateService::class);
    $state = $service->generate($org, $user);

    expect($service->consume($state, $user)->extra)->toBe([]);
});

it('destroys the extra along with the state it belongs to', function () {
    // Single use is the whole point: a verifier that outlives its state
    // would be replayable for the rest of the TTL.
    $org = Organization::factory()->create();
    $user = actingAsMember($org, 'ADMIN');

    $service = app(OAuthStateService::class);
    $state = $service->generate($org, $user);
    $service->remember($state, ['code_verifier' => 'v-123']);

    $service->consume($state, $user);

    expect(fn () => $service->consume($state, $user))->toThrow(InvalidOAuthStateException::class);
});

it('ignores a remember() for a state that does not exist', function () {
    // A provider cannot conjure a state entry by remembering against a
    // guessed value: no state, no storage.
    $org = Organization::factory()->create();
    $user = actingAsMember($org, 'ADMIN');

    app(OAuthStateService::class)->remember('a-state-nobody-minted', ['code_verifier' => 'v-123']);

    expect(fn () => app(OAuthStateService::class)->consume('a-state-nobody-minted', $user))
        ->toThrow(InvalidOAuthStateException::class);
});

it('lets the server-stored extra win over the same key sent by the browser', function () {
    // The attack this closes: the callback body is entirely
    // browser-controlled, so without this precedence a page could send
    // its own `code_verifier` (or Mastodon `instance`, or a page id) and
    // complete the flow against a value the server never issued.
    // FakeProvider names the integration after $params['name'], which
    // makes the winner visible in the response.
    $org = Organization::factory()->create();
    $user = actingAsMember($org, 'ADMIN');

    $service = app(OAuthStateService::class);
    $state = $service->generate($org, $user);
    $service->remember($state, ['name' => 'from-the-server']);

    $response = $this->postJson('/api/integrations/fake/callback', [
        'state' => $state,
        'name' => 'from-the-browser',
    ]);

    $response->assertCreated();
    expect($response->json('name'))->toBe('from-the-server');
    // withoutGlobalScope: the callback route carries no `organization`
    // middleware (the org comes from the state), so this test process has
    // no resolved tenant context to scope the read by.
    expect(Integration::query()->withoutGlobalScope('organization')
        ->where('organization_id', $org->id)->value('name'))->toBe('from-the-server');
});

it('stores the extra a provider returned from auth-url without the browser having to carry it', function () {
    // End to end: the SPA never resupplies anything here, and the connect
    // still completes with the provider's own value.
    $org = Organization::factory()->create();
    $user = actingAsMember($org, 'ADMIN');

    $this->postJson('/api/integrations/fake/auth-url')->assertOk();

    $state = app(OAuthStateService::class)->generate($org, $user);
    app(OAuthStateService::class)->remember($state, ['internal_id' => 'remembered-account']);

    $this->postJson('/api/integrations/fake/callback', ['state' => $state])->assertCreated();

    expect(Integration::query()->withoutGlobalScope('organization')
        ->where('organization_id', $org->id)->value('internal_id'))->toBe('remembered-account');
});
