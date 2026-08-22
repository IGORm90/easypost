<?php

use App\Enums\PostState;
use App\Enums\RepeatUnit;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Tag;
use Illuminate\Support\Facades\Queue;

/*
 * Task 20: POST /api/posts — one post_group per request, one Post row
 * per (integration, chain segment). Mirrors the brief's five required
 * scenarios plus the extra cross-tenant coverage the task calls for on
 * every mutating endpoint.
 */

// Task 30: PostGroupService now dispatches PublishPostJob whenever a
// group enters QUEUE (type=schedule/now). This suite runs on the sync
// queue driver, which would otherwise execute the real publish pipeline
// inline mid-test — Queue::fake() keeps these tests focused on CRUD/state
// behaviour, exactly as before Task 30. See PublishPostJobTest for
// coverage of the job itself.
beforeEach(fn () => Queue::fake());

it('creates one post per integration inside a single group', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    [$a, $b] = Integration::factory()->for($org)->count(2)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'posts' => [
            ['integration_id' => $a->id, 'value' => [['content' => 'A']]],
            ['integration_id' => $b->id, 'value' => [['content' => 'B']]],
        ],
    ]);

    $response->assertCreated();

    expect(PostGroup::count())->toBe(1)->and(Post::count())->toBe(2);
});

it('stores a thread as parent and children in order', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            [
                'integration_id' => $integration->id,
                'value' => [
                    ['content' => 'root'],
                    ['content' => 'child one'],
                    ['content' => 'child two'],
                ],
            ],
        ],
    ]);

    $response->assertCreated();

    $group = PostGroup::sole();
    $posts = Post::query()->where('post_group_id', $group->id)->orderBy('order')->get();

    expect($posts)->toHaveCount(3);

    $root = $posts[0];
    $child1 = $posts[1];
    $child2 = $posts[2];

    expect($root->parent_post_id)->toBeNull()
        ->and($root->order)->toBe(0)
        ->and($root->content)->toBe('root')
        ->and($child1->parent_post_id)->toBe($root->id)
        ->and($child1->order)->toBe(1)
        ->and($child1->content)->toBe('child one')
        // Linear chain, not a star: each segment replies to the
        // previous one (see PostPayloadDto's docblock — "subsequent
        // elements are replies to the previous one, in order"), so
        // child two's parent is child one, not the root directly.
        ->and($child2->parent_post_id)->toBe($child1->id)
        ->and($child2->order)->toBe(2)
        ->and($child2->content)->toBe('child two');
});

it('rejects a post that violates provider limits', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'bluesky']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            [
                'integration_id' => $integration->id,
                'value' => [
                    ['content' => str_repeat('a', 400)],
                ],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.value.0.content']);
    expect(PostGroup::count())->toBe(0)->and(Post::count())->toBe(0);
});

it("forbids scheduling into another organization's integration", function () {
    $mine = Organization::factory()->create();
    actingAsMember($mine);
    $other = Organization::factory()->create();
    $foreign = Integration::factory()->for($other)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $foreign->id, 'value' => [['content' => 'A']]],
        ],
    ]);

    $response->assertForbidden();
    expect(PostGroup::count())->toBe(0)->and(Post::count())->toBe(0);
});

it('rejects a publish_date in the past for type=schedule', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->subDay()->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A']]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['publish_date']);
});

it('sets state to QUEUE with publish_date=now for type=now, ignoring any date sent', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $before = now();

    $response = $this->postJson('/api/posts', [
        'type' => 'now',
        // Deliberately a stale, far-past date: type=now must ignore
        // whatever publish_date the client sends and stamp its own —
        // see PostGroupService::resolvePublishDate()'s docblock.
        'publish_date' => now()->subYear()->toIso8601String(),
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A']]],
        ],
    ]);

    $response->assertCreated();

    $group = PostGroup::sole();
    expect($group->state)->toBe(PostState::QUEUE)
        ->and($group->publish_date->between($before->clone()->subSecond(), now()->addSecond()))->toBeTrue();

    $post = Post::sole();
    expect($post->state)->toBe(PostState::QUEUE);
});

it('leaves a draft group and its posts in DRAFT state', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A']]],
        ],
    ]);

    $response->assertCreated();

    $group = PostGroup::sole();
    expect($group->state)->toBe(PostState::DRAFT);
    expect(Post::sole()->state)->toBe(PostState::DRAFT);
});

it('attaches the given tags to the new group', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $tag = Tag::factory()->for($org)->create();

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'tags' => [$tag->id],
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A']]],
        ],
    ]);

    $response->assertCreated();
    $group = PostGroup::sole();
    expect($group->tags->pluck('id')->all())->toBe([$tag->id]);
});

it('accepts a well-formed, owned media id and persists it to the media_post pivot', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->create();

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$media->id]]]],
        ],
    ]);

    $response->assertCreated();
    // Task 28: media_ids is now re-validated for existence and
    // organization ownership (see PostMediaTest for the 422 cases) and
    // persisted to the real media_post pivot, not settings jsonb.
    $response->assertJsonPath('posts.0.value.0.media_ids.0', $media->id);
    expect(Post::sole()->media()->pluck('media.id')->all())->toBe([$media->id]);
});

it('rejects a malformed media id', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => ['not-a-ulid']]]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.value.0.media_ids.0']);
    expect(PostGroup::count())->toBe(0);
});

it('rejects two chains for the same integration in one request', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A']]],
            ['integration_id' => $integration->id, 'value' => [['content' => 'B']]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.integration_id', 'posts.1.integration_id']);
    expect(PostGroup::count())->toBe(0);
});

it('does not create anything when validation fails mid-request (transactional)', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    [$good, $bad] = Integration::factory()->for($org)->count(2)->create(['provider_identifier' => 'fake']);
    $bad->update(['provider_identifier' => 'bluesky']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $good->id, 'value' => [['content' => 'fine']]],
            ['integration_id' => $bad->id, 'value' => [['content' => str_repeat('a', 400)]]],
        ],
    ]);

    $response->assertStatus(422);
    expect(PostGroup::count())->toBe(0)->and(Post::count())->toBe(0);
});

/*
 * Block 4: the recurrence rule travels on the same payload as the post
 * itself (`repeat_unit`, `repeat_interval`, `repeat_until`), validated in
 * ValidatesPostChains, which StorePostRequest and UpdatePostRequest
 * share verbatim — so covering it here covers both entry points'
 * structural rules; UpdatePostTest covers what changes on a PUT.
 */
it('stores a recurrence rule alongside the post', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'repeat_unit' => 'WEEK',
        'repeat_interval' => 2,
        'repeat_until' => now()->addMonths(3)->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'weekly']]]],
    ])->assertCreated()
        // No `data` wrapper: PostController hands the resource to
        // response()->json(), which serialises it directly.
        ->assertJsonPath('repeat_unit', 'WEEK')
        ->assertJsonPath('repeat_interval', 2);

    $group = PostGroup::first();
    expect($group->repeat_unit)->toBe(RepeatUnit::WEEK)
        ->and($group->repeat_interval)->toBe(2)
        ->and($group->repeat_until)->not->toBeNull()
        // The first post of a series is its own root: nothing to point at yet.
        ->and($group->repeat_parent_id)->toBeNull()
        ->and($group->repeat_spawned_at)->toBeNull();
});

it('rejects a recurrence rule with no end date', function () {
    // An endless series quietly eats the organization's monthly quota
    // forever, and the person who notices is whoever later cannot save
    // an ordinary post. The limiter is a required parameter, not a
    // convenience.
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'repeat_unit' => 'DAY',
        'repeat_interval' => 1,
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'x']]]],
    ])->assertStatus(422)->assertJsonValidationErrors('repeat_until');
});

it('rejects a repeat interval with no unit', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'repeat_interval' => 3,
        'repeat_until' => now()->addMonth()->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'x']]]],
    ])->assertStatus(422)->assertJsonValidationErrors('repeat_unit');
});

it('rejects an interval beyond what its unit can mean', function ($unit, $interval) {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addDay()->toIso8601String(),
        'repeat_unit' => $unit,
        'repeat_interval' => $interval,
        'repeat_until' => now()->addMonths(2)->toIso8601String(),
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'x']]]],
    ])->assertStatus(422)->assertJsonValidationErrors('repeat_interval');
})->with([
    ['DAY', 366],
    ['WEEK', 53],
    ['MONTH', 13],
    ['DAY', 0],
]);

it('rejects an end date in the past, further than a year out, or before the post itself', function ($until) {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addMonth()->toIso8601String(),
        'repeat_unit' => 'DAY',
        'repeat_interval' => 1,
        'repeat_until' => $until,
        'posts' => [['integration_id' => $integration->id, 'value' => [['content' => 'x']]]],
    ])->assertStatus(422)->assertJsonValidationErrors('repeat_until');
})->with([
    'in the past' => [fn () => now()->subDay()->toIso8601String()],
    'more than a year out' => [fn () => now()->addYears(2)->toIso8601String()],
    'before the post publishes' => [fn () => now()->addWeek()->toIso8601String()],
]);
