<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\PublishService;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\Http;

/*
 * Final review, Critical 4: Slack was unusable end to end.
 *
 * SlackProvider::authenticate() returns no `settings` (unlike every other
 * provider), so `settings.channel_id` — which post() requires and refuses
 * to publish without — could only ever be set through
 * `PUT /api/integrations/{id}`. The backend half of that existed:
 * `GET /api/integrations/{id}/options/channels` and Slack's
 * `updatableSettings()` declaration. The frontend never called either, and
 * the settings drawer's PUT body carried no `settings` key at all. Every
 * Slack post scheduled through the product went straight to ERROR.
 *
 * The unit suite hid it by hand-writing `settings.channel_id` on the
 * fixture. This test does the opposite on purpose: it drives ONLY the
 * HTTP calls a user can actually cause — connect, list options, save the
 * chosen one — and then publishes for real. Nothing writes `settings`
 * except the API itself.
 */

beforeEach(function () {
    config([
        'services.slack.integration.client_id' => 'slack-client-id',
        'services.slack.integration.client_secret' => 'slack-client-secret',
    ]);
});

afterEach(function () {
    OrganizationContext::clear();
});

it('connects Slack, picks a channel through the options endpoint, and publishes — with no hand-written settings', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-real-token',
            'team' => ['id' => 'T9TK3CUKW', 'name' => 'Acme workspace'],
        ]),
        'slack.com/api/conversations.list*' => Http::response([
            'ok' => true,
            'channels' => [
                ['id' => 'C0EAQDV4Z', 'name' => 'general'],
                ['id' => 'C0EAQDV50', 'name' => 'announcements'],
            ],
        ]),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
    ]);

    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');

    // 1. Connect, exactly as ConnectProviderModal does.
    $state = $this->postJson('/api/integrations/slack/auth-url')->assertOk()->json('state');
    $integrationId = $this->postJson('/api/integrations/slack/callback', ['state' => $state, 'code' => 'the-code'])
        ->assertCreated()
        ->json('id');

    // The connect flow itself leaves the channel unset — this is the gap
    // the picker exists to close, and asserting it here keeps this test
    // honest if a future change starts populating it at connect time.
    expect($this->getJson('/api/integrations')->json('0.settings'))->not->toHaveKey('channel_id');

    // 2. Read the choices the same generic way the drawer must.
    $options = $this->getJson("/api/integrations/{$integrationId}/options/channels")->assertOk()->json('options');
    expect($options)->toBe([
        ['value' => 'C0EAQDV4Z', 'label' => 'general'],
        ['value' => 'C0EAQDV50', 'label' => 'announcements'],
    ]);

    // 3. Save the pick through the settings drawer's own endpoint.
    $this->putJson("/api/integrations/{$integrationId}", [
        'name' => 'Acme workspace',
        'settings' => ['channel_id' => $options[1]['value']],
    ])->assertOk();

    // 4. Publish for real.
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->subMinute(),
    ]);
    $post = Post::factory()->create([
        'organization_id' => $org->id,
        'post_group_id' => $group->id,
        'integration_id' => $integrationId,
        'state' => PostState::QUEUE,
        'content' => 'Hello from the scheduler',
    ]);

    OrganizationContext::clear();
    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
        && $request->data()['channel'] === 'C0EAQDV50');
});

it('advertises the channel picker as a declared capability, naming no provider', function () {
    actingAsMember(Organization::factory()->create());

    $providers = collect((array) $this->getJson('/api/providers')->assertOk()->json())->keyBy('identifier');

    // The frontend must be able to learn "this channel has a setting the
    // user picks from a server-provided list, and here is the list's
    // name" without ever matching on an identifier — see
    // resources/js/__tests__/providerIsolation.spec.ts.
    expect($providers['slack']['settings_fields'])->toBe([
        ['key' => 'channel_id', 'option' => 'channels'],
    ]);

    // A provider with nothing to pick declares nothing, rather than the
    // frontend having to know which ones those are.
    expect($providers['telegram']['settings_fields'])->toBe([]);
});
