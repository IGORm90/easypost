<?php

use App\Models\Integration;
use App\Models\Organization;

/*
 * Block 5.0.6: some networks need per-POST choices, not per-channel ones
 * — Reddit refuses a submission without a subreddit, a title and (often)
 * a flair, and X takes a reply-permission setting. `posts.settings` has
 * carried arbitrary data end to end since Task 20, but nothing ever
 * declared what belonged in it, so nothing could render a form for it and
 * nothing could validate it.
 *
 * The declaration is published by `GET /api/providers`, exactly like
 * `auth_fields` and `settings_fields` already are, because the frontend
 * is not allowed to know which network is which — a Reddit-shaped form
 * living in the SPA is precisely what providerIsolation.spec.ts fails on.
 */

it('publishes an empty post-settings declaration for a provider that declares none', function () {
    actingAsMember(Organization::factory()->create());

    $response = $this->getJson('/api/providers')->assertOk();

    /** @var list<array<string, mixed>> $providers */
    $providers = (array) $response->json();
    $fake = collect($providers)->firstWhere('identifier', 'fake');

    expect($fake)->toHaveKey('post_settings');
    expect($fake['post_settings'])->toBe([]);
});

it('publishes the fields a provider declares, in the order it declared them', function () {
    actingAsMember(Organization::factory()->create());

    $response = $this->getJson('/api/providers')->assertOk();

    /** @var list<array<string, mixed>> $providers */
    $providers = (array) $response->json();
    $provider = collect($providers)->firstWhere('identifier', 'post-settings-fake');

    expect($provider)->not->toBeNull();
    expect(array_column($provider['post_settings'], 'key'))->toBe(['topic', 'title', 'flavour']);
    expect($provider['post_settings'][0])->toMatchArray([
        'key' => 'topic',
        'type' => 'select',
        'required' => true,
        'option' => 'topics',
    ]);
    // `dependsOn` is what lets one picker's list depend on another's
    // value (Reddit: flairs exist only for a chosen subreddit).
    expect($provider['post_settings'][2]['dependsOn'])->toBe('topic');
});

it('never publishes the validation rules to the client', function () {
    // The rules are the server's business. Shipping them invites the SPA
    // to reimplement Laravel's validator badly, and the two would then
    // disagree the first time a rule changed.
    actingAsMember(Organization::factory()->create());

    /** @var list<array<string, mixed>> $providers */
    $providers = (array) $this->getJson('/api/providers')->json();
    $provider = collect($providers)->firstWhere('identifier', 'post-settings-fake');

    foreach ($provider['post_settings'] as $field) {
        expect($field)->not->toHaveKey('rules');
    }
});

// --- options with context --------------------------------------------------

it('passes context through to the provider when listing options', function () {
    // A dependent list cannot be fetched without knowing what it depends
    // on: Reddit's flairs are a property of one subreddit, not of the
    // account.
    $org = Organization::factory()->create();
    actingAsMember($org);

    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'post-settings-fake',
    ]);

    $response = $this->getJson("/api/integrations/{$integration->id}/options/flavours?context[topic]=jazz")
        ->assertOk();

    expect(array_column($response->json('options'), 'value'))->toBe(['jazz-smooth', 'jazz-free']);
});

it('lists a dependent option as empty rather than failing when its context is missing', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'post-settings-fake',
    ]);

    $response = $this->getJson("/api/integrations/{$integration->id}/options/flavours")->assertOk();

    expect($response->json('options'))->toBe([]);
});

it('rejects a context that is not a flat map of strings', function () {
    // The value reaches a provider that will put it in an outbound URL.
    // One level, strings only — a nested array here is either a mistake
    // or an attempt to smuggle structure past the provider's expectations.
    $org = Organization::factory()->create();
    actingAsMember($org);

    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'post-settings-fake',
    ]);

    $this->getJson("/api/integrations/{$integration->id}/options/flavours?context[topic][nested]=x")
        ->assertStatus(422);
});
