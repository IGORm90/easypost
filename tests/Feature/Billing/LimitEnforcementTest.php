<?php

use App\Enums\OrganizationRole;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Billing\Exceptions\PlanLimitExceededException;
use App\Services\Integrations\IntegrationService;
use App\Services\Posts\PostGroupService;
use App\Social\Dto\AuthResultDto;
use App\Support\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Task 36: wires Task 35's PlanLimitService to the three creation
 * points (channel connect, post creation, media upload) and proves the
 * 402 shape a caller actually gets back. Free plan (config/plans.php):
 * channels=2, posts_per_month=30, media_storage_mb=200.
 *
 * `plan:channels` sits on `auth-url`, not `callback` — see
 * EnforcePlanLimit's docblock for why. auth-url is what
 * ConnectProviderModal.vue always calls first, for both OAuth and
 * manual-token providers, so it is the one place a "third channel"
 * attempt genuinely starts.
 */

// --- Channels --------------------------------------------------------

it('402s auth-url on the free plan\'s third channel, with the {limit, current, max} body', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    Integration::factory()->for($org)->count(2)->create();

    $response = $this->postJson('/api/integrations/fake/auth-url');

    $response->assertStatus(402)->assertJson([
        'limit' => 'channels',
        'current' => 2,
        'max' => 2,
    ]);
    $message = $response->json('message');
    expect($message)->toBeString();
    expect($message)->not->toBe('');
});

it('allows auth-url again after moving the organization to a plan with more room', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    Integration::factory()->for($org)->count(2)->create();

    $this->postJson('/api/integrations/fake/auth-url')->assertStatus(402);

    $this->artisan("org:plan {$org->id} standard")->assertSuccessful();

    $this->postJson('/api/integrations/fake/auth-url')->assertOk();
});

it('does not 402 a reconnect of an already-live channel, since it does not raise the count', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    Integration::factory()->for($org)->count(2)->create(['provider_identifier' => 'fake']);

    // Starting a genuinely NEW connect flow (no integration named) is
    // still gated (already at 2/2)...
    $this->postJson('/api/integrations/fake/auth-url')->assertStatus(402);

    // ...but IntegrationService::connect() itself must still allow a
    // reconnect (token refresh) of one of the two already-live channels
    // — it does not increase channelCount(), so it must never be
    // rejected by the plan limit. Exercised directly at the service
    // layer to prove withLimit() itself — not just
    // EnforcePlanLimit's reconnect-aware pre-check (covered separately
    // below, through the actual HTTP route a real reconnect uses) —
    // treats this correctly.
    $existing = Integration::where('organization_id', $org->id)->first();
    $result = app(IntegrationService::class)->connect(
        $org,
        'fake',
        new AuthResultDto($existing->internal_id, 'Reconnected', 'new-token'),
    );

    expect($result->id)->toBe($existing->id)
        ->and(Integration::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(2);
});

it('lets a reconnect of a broken channel through auth-url when the request names it, even at 2/2', function () {
    // Critical 2 (round 1 review): `POST .../auth-url` is the only path
    // ChannelCard.vue's "Reconnect" button offers for a channel stuck in
    // `refresh_needed` — Channels/Index.vue's openReconnect() ->
    // ConnectProviderModal.vue -> stores/integrations.ts all route
    // through this exact endpoint, never IntegrationService::connect()
    // directly. A free org already at 2/2 must still be able to use it
    // to repair its own existing channel, since doing so never raises
    // channelCount() — only a request that does NOT name a live
    // integration in this organization (a genuinely new connect) stays
    // gated.
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $broken = Integration::factory()->for($org)->create(['provider_identifier' => 'fake', 'refresh_needed' => true]);

    $this->postJson('/api/integrations/fake/auth-url', ['integration_id' => $broken->id])
        ->assertOk();

    $this->postJson('/api/integrations/fake/auth-url')
        ->assertStatus(402);
});

it('rejects a genuinely new channel at IntegrationService::connect() itself, not just the pre-check', function () {
    // Proves withLimit(), not the route middleware, is the real guard:
    // calling the service directly, bypassing auth-url entirely.
    $org = Organization::factory()->create(['plan' => 'free']);
    Integration::factory()->for($org)->count(2)->create();

    app(IntegrationService::class)->connect(
        $org,
        'fake',
        new AuthResultDto('brand-new-account', 'Third Channel', 'tok'),
    );
})->throws(PlanLimitExceededException::class);

// --- Posts -------------------------------------------------------------

it('402s post creation on the free plan\'s 31st post this month, with the {limit, current, max} body', function () {
    Queue::fake();
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    PostGroup::factory()->for($org)->count(30)->create();

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'one too many']]],
        ],
    ]);

    $response->assertStatus(402)->assertJson([
        'limit' => 'posts_per_month',
        'current' => 30,
        'max' => 30,
    ]);
    expect(PostGroup::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(30);
});

it('allows post creation again after moving the organization to a plan with more room', function () {
    Queue::fake();
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    PostGroup::factory()->for($org)->count(30)->create();

    $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'blocked']]]],
    ])->assertStatus(402);

    $this->artisan("org:plan {$org->id} standard")->assertSuccessful();

    $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'now allowed']]]],
    ])->assertCreated();
});

it('402s duplicate on the free plan\'s monthly post limit, and creates no new PostGroup row', function () {
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    PostGroup::factory()->for($org)->count(29)->create();
    $source = PostGroup::factory()->for($org)->create();

    $response = $this->postJson("/api/posts/{$source->id}/duplicate");

    $response->assertStatus(402)->assertJson([
        'limit' => 'posts_per_month',
        'current' => 30,
        'max' => 30,
    ]);
    expect(PostGroup::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(30);
});

// --- Media ---------------------------------------------------------------

it('402s a direct media upload that would push the org over its storage limit, with the {limit, current, max} body', function () {
    Storage::fake('media');
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    // One byte short of the 200 MB ceiling: any positive-size upload pushes over.
    Media::factory()->for($org)->create(['size_bytes' => 200 * 1024 * 1024 - 1]);

    $response = $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg', 10, 10)]);

    $response->assertStatus(402)->assertJson(['limit' => 'media_storage_mb', 'max' => 200]);
    expect(Media::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(1);
});

// --- No double-counting ---------------------------------------------------

it('does not double-count: a request that clears the middleware pre-check and is the only racer succeeds exactly once', function () {
    Queue::fake();
    $org = Organization::factory()->create(['plan' => 'free']);
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    PostGroup::factory()->for($org)->count(29)->create(); // one slot free (29/30)

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'the last one']]]],
    ]);

    $response->assertCreated();
    // 30, not 31 or 29 — the middleware's check() and PostGroupService's
    // withLimit() both evaluated the SAME single creation event; neither
    // reserved or consumed quota on its own, so passing through both
    // layers still creates exactly one row.
    expect(PostGroup::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(30);
});

// --- The real guarantee: withLimit() at the actual insert, under a genuine race ---

it('never lets two concurrent HTTP-shaped post creations both pass a limit of one, through PostGroupService::create()', function () {
    // Sequential Pest/HTTP calls can never reproduce this race — see
    // raceConcurrently()'s docblock in tests/Pest.php. This exercises
    // the REAL production call path (PostGroupService::create(), the
    // exact method PostController::store() calls) rather than calling
    // PlanLimitService::withLimit() directly the way
    // PlanLimitServiceTest's own race test does — proving Task 36's
    // wiring, not just Task 35's service, holds under concurrency.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, ['name' => 'Post Wiring Race Owner', 'email' => 'post-wiring-race-'.Str::random(10).'@example.com', 'password' => 'irrelevant-hash']);
    $organization = raceCreate(Organization::class, ['name' => 'Post Wiring Race Co', 'slug' => 'post-wiring-race-'.Str::lower(Str::random(10)), 'owner_id' => $owner->id, 'plan' => 'free']);
    raceCreate(OrganizationUser::class, ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::ADMIN]);
    $integration = raceCreate(Integration::class, [
        'organization_id' => $organization->id,
        'provider_identifier' => 'fake',
        'internal_id' => 'race-wiring-account',
        'name' => 'Race Wiring Channel',
        'token' => 'race-token',
        'refresh_token' => 'race-refresh',
        'settings' => [],
    ]);

    $originalLimits = config('plans.free.limits');
    config(['plans.free.limits' => array_merge($originalLimits, ['posts_per_month' => 1])]);

    $organizationId = $organization->id;
    $integrationId = $integration->id;
    $racerCount = 5;

    try {
        $racers = array_map(
            fn (int $i) => function () use ($organizationId, $integrationId, $i) {
                $organization = Organization::findOrFail($organizationId);
                OrganizationContext::set($organization);

                app(PostGroupService::class)->create([
                    'type' => 'draft',
                    'posts' => [
                        ['integration_id' => $integrationId, 'value' => [['content' => "racer {$i}"]]],
                    ],
                ]);
            },
            range(1, $racerCount),
        );

        $results = raceConcurrently($racers);

        $succeeded = array_filter($results, fn ($r) => $r === 'ok');
        $failed = array_filter($results, fn ($r) => $r !== 'ok');

        expect($succeeded)->toHaveCount(1);
        foreach ($failed as $result) {
            expect($result)->toBe('fail:App\Services\Billing\Exceptions\PlanLimitExceededException');
        }

        $count = DB::connection('race')->table('post_groups')
            ->where('organization_id', $organizationId)
            ->count();

        expect($count)->toBe(1);
    } finally {
        config(['plans.free.limits' => $originalLimits]);
        DB::connection('race')->table('posts')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('post_groups')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('integrations')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organization_user')->where('organization_id', $organizationId)->delete();
        DB::connection('race')->table('organizations')->where('id', $organizationId)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});
