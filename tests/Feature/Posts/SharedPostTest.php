<?php

use App\Models\Comment;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Task 22: POST|DELETE /api/posts/{group}/share and the public,
 * unauthenticated GET /api/public/posts/{token}. The public endpoint is
 * this task's whole point (see the brief) — it deliberately steps
 * outside BelongsToOrganization's tenant scope in exactly one place
 * (ShareService::findForPublic()'s lookup by share_token) and must
 * return nothing beyond content and preview data: no channel tokens, no
 * member emails, no ids that let a caller enumerate anything else, no
 * comments, no organization identifiers.
 */

beforeEach(function () {
    RateLimiter::clear('public-share:127.0.0.1');
});

it('generates a share token and url for a group', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();

    $response = $this->postJson("/api/posts/{$group->id}/share");

    $response->assertOk();
    $group->refresh();
    expect($group->share_token)->not->toBeNull();
    expect(mb_strlen($group->share_token))->toBeGreaterThanOrEqual(48);
    expect($response->json('url'))->toContain($group->share_token);
});

it('revokes a share token', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create(['share_token' => 'a-real-token']);

    $response = $this->deleteJson("/api/posts/{$group->id}/share");

    $response->assertNoContent();
    expect($group->fresh()->share_token)->toBeNull();
});

it("404s sharing another organization's group", function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $other = Organization::factory()->create();
    $foreignGroup = PostGroup::factory()->for($other)->create();

    $this->postJson("/api/posts/{$foreignGroup->id}/share")->assertNotFound();
});

it('serves the exact content/preview shape for a valid token, with no session required', function () {
    $org = Organization::factory()->create();
    $user = actingAsMember($org);
    $integration = Integration::factory()->for($org)->create([
        'provider_identifier' => 'fake',
        'name' => 'My Channel',
        'picture' => 'https://example.com/pic.png',
        'token' => 'super-secret-oauth-token',
    ]);
    $group = PostGroup::factory()->for($org)->create([
        'share_token' => null,
        'publish_date' => '2026-09-01T10:00:00Z',
        'created_by' => $user->id,
    ]);
    Post::factory()->for($group, 'group')->for($org)->for($integration)->create([
        'order' => 0,
        'parent_post_id' => null,
        'content' => 'Hello world',
    ]);
    Comment::factory()->for($group, 'group')->for($user)->create(['content' => 'internal note, must never leak']);

    $this->postJson("/api/posts/{$group->id}/share")->assertOk();
    $token = $group->fresh()->share_token;

    // The public route carries no auth:sanctum/organization middleware at
    // all (see routes/api.php), so this call succeeding proves the
    // endpoint needs neither the session nor the X-Organization header
    // the earlier calls in this test happened to carry.
    $response = $this->getJson("/api/public/posts/{$token}");

    $response->assertOk();
    $response->assertExactJson([
        'publish_date' => '2026-09-01T10:00:00.000000Z',
        'timezone' => $user->timezone,
        'channels' => [
            [
                'provider_identifier' => 'fake',
                'name' => 'My Channel',
                'picture' => 'https://example.com/pic.png',
                'content' => ['Hello world'],
            ],
        ],
    ]);

    $raw = $response->getContent();
    expect($raw)->not->toContain('super-secret-oauth-token');
    expect($raw)->not->toContain($user->email);
    expect($raw)->not->toContain('internal note');
    expect($raw)->not->toContain($group->id);
    expect($raw)->not->toContain($integration->id);
    expect($raw)->not->toContain($org->id);
});

/*
 * Final review, Minor: SharedPost.vue rendered the publish date with
 * `new Date(...).toLocaleString()`, i.e. in the VIEWER's timezone — on
 * the one page whose entire purpose is telling a client when something
 * goes out. There is no organization-level timezone column; the zone a
 * post was actually scheduled in is the scheduler's own profile timezone,
 * which is what the calendar grid and the editor both render in, so that
 * is what the share link has to echo.
 */
it('reports the timezone the post was scheduled in, so a share link does not read in the viewer\'s zone', function () {
    $org = Organization::factory()->create();
    $author = actingAsMember($org);
    $author->update(['timezone' => 'Europe/Moscow']);
    $group = PostGroup::factory()->for($org)->create([
        'publish_date' => '2026-09-01T10:00:00Z',
        'created_by' => $author->id,
    ]);

    $this->postJson("/api/posts/{$group->id}/share")->assertOk();

    $response = $this->getJson('/api/public/posts/'.$group->fresh()->share_token);

    $response->assertOk();
    expect($response->json('timezone'))->toBe('Europe/Moscow');
});

it('falls back to UTC when the group has no creator left to take a timezone from', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create(['created_by' => null]);

    $this->postJson("/api/posts/{$group->id}/share")->assertOk();

    $response = $this->getJson('/api/public/posts/'.$group->fresh()->share_token);

    $response->assertOk();
    expect($response->json('timezone'))->toBe('UTC');
});

/*
 * Final review, batch 4 (tenancy minors). `Integration` is soft-deleted,
 * and disconnecting a channel is an everyday act — so a share link
 * minted while three channels were connected 500s the moment one of them
 * is disconnected: the relation resolves to null and the resource reads
 * `provider_identifier` off it. The link is public and already handed
 * out; the failure lands on the client, not the author, and it is a 500
 * rather than anything explicable.
 */
it('still serves a share link after one of its channels is disconnected', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $kept = Integration::factory()->for($org)->create(['provider_identifier' => 'fake', 'name' => 'Kept']);
    $gone = Integration::factory()->for($org)->create(['provider_identifier' => 'fake', 'name' => 'Gone']);
    $group = PostGroup::factory()->for($org)->create(['publish_date' => '2026-09-01T10:00:00Z']);
    Post::factory()->for($org)->for($group, 'group')->for($kept)->create(['content' => 'still here', 'order' => 0]);
    Post::factory()->for($org)->for($group, 'group')->for($gone)->create(['content' => 'channel is gone', 'order' => 0]);

    $this->postJson("/api/posts/{$group->id}/share")->assertOk();

    $gone->delete();

    $response = $this->getJson('/api/public/posts/'.$group->fresh()->share_token);

    $response->assertOk();

    // The disconnected channel's content is still shown — the page's
    // whole purpose is reporting what went out, and it did go out. What
    // is gone is the channel's live identity, not the post.
    /** @var list<array{name: string}> $channels */
    $channels = $response->json('channels');
    $names = array_map(fn (array $channel): string => $channel['name'], $channels);
    sort($names);

    expect($channels)->toHaveCount(2)
        ->and($names)->toBe(['Gone', 'Kept']);
});

it('404s a revoked token exactly like an unknown one', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();
    $this->postJson("/api/posts/{$group->id}/share")->assertOk();
    $token = $group->fresh()->share_token;
    $this->deleteJson("/api/posts/{$group->id}/share")->assertNoContent();

    $revoked = $this->getJson("/api/public/posts/{$token}");
    $unknown = $this->getJson('/api/public/posts/not-a-real-token-at-all');

    $revoked->assertNotFound();
    $unknown->assertNotFound();
    expect($revoked->getStatusCode())->toBe($unknown->getStatusCode());
    // Compare the `message` key only, not the full body: with APP_DEBUG
    // on (the test env's default) Laravel's exception renderer also
    // includes a stack trace/file/line, which necessarily differs
    // between these two calls simply because they're two different call
    // sites — that's a debug-mode artifact, not a behavioural difference
    // a real caller (APP_DEBUG=false in every deployed environment)
    // would ever observe.
    expect($revoked->json('message'))->toBe($unknown->json('message'));
});

it('404s for a group that was deleted after its token was minted', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();
    $this->postJson("/api/posts/{$group->id}/share")->assertOk();
    $token = $group->fresh()->share_token;
    $group->delete();

    $this->getJson("/api/public/posts/{$token}")->assertNotFound();
});

it('throttles repeated public lookups from the same IP', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = PostGroup::factory()->for($org)->create();
    $this->postJson("/api/posts/{$group->id}/share")->assertOk();
    $token = $group->fresh()->share_token;

    for ($i = 0; $i < 30; $i++) {
        $this->getJson("/api/public/posts/{$token}")->assertOk();
    }

    $this->getJson("/api/public/posts/{$token}")->assertStatus(429);
});
