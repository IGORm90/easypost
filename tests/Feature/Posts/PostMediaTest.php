<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Tag;
use App\Services\Posts\PostGroupService;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Task 28: attaching media to posts. media_ids in the POST/PUT
 * /api/posts payload must persist to the media_post pivot with order
 * preserved, PostResource must expose the richer {id,url,thumbnail_url,
 * mime,width,height} shape, and media_ids must be re-validated for
 * existence + organization ownership (unlike Task 20's shape-only
 * check) and checked against the target provider's ProviderCapabilities
 * through PostValidationService.
 */

// Task 30: PostGroupService now dispatches PublishPostJob whenever a
// group enters QUEUE (type=schedule/now) — see CreatePostTest's
// identical note.
beforeEach(fn () => Queue::fake());

it('persists media_ids to media_post with order preserved and reads it back in order', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->count(3)->create();

    // Deliberately reversed vs. creation order, to prove the response
    // order comes from what was sent, not from the media table's own
    // id/creation order.
    $orderedIds = [$media[2]->id, $media[0]->id, $media[1]->id];

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => $orderedIds]]],
        ],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('posts.0.value.0.media_ids', $orderedIds);

    $post = Post::sole();
    expect($post->media()->orderByPivot('order')->pluck('media.id')->all())->toBe($orderedIds);

    // Round-trips on GET too, not just the create response.
    $group = PostGroup::sole();
    $show = $this->getJson("/api/posts/{$group->id}")->assertOk();
    $show->assertJsonPath('posts.0.value.0.media_ids', $orderedIds);

    $firstMedia = $show->json('posts.0.value.0.media.0');
    expect($firstMedia)->toHaveKeys(['id', 'url', 'thumbnail_url', 'mime', 'width', 'height']);
    expect($firstMedia['id'])->toBe($media[2]->id);
    expect($firstMedia['mime'])->toBe($media[2]->mime_type);
    // Real values, not the pre-review-fix hardcoded null -- MediaFactory
    // always sets both.
    expect($firstMedia['width'])->toBe($media[2]->width);
    expect($firstMedia['height'])->toBe($media[2]->height);
});

it('passes through null width/height in PostResource for a media row with no captured dimensions', function () {
    // Pins the null-dimension path through PostResource specifically:
    // MediaFactory always sets real dimensions, so nothing else in this
    // suite exercises a genuinely-null value here (e.g. a video, or an
    // image getimagesize() couldn't parse at upload time).
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->create(['width' => null, 'height' => null]);

    $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$media->id]]]],
        ],
    ])->assertCreated();

    $group = PostGroup::sole();
    $show = $this->getJson("/api/posts/{$group->id}")->assertOk();

    $show->assertJsonPath('posts.0.value.0.media.0.width', null);
    $show->assertJsonPath('posts.0.value.0.media.0.height', null);
});

it('rejects an object-shaped media_ids instead of 500ing on a non-integer pivot order', function () {
    // Review Finding 1: {"a": "<ulid>"} used to pass the old 'array'-only
    // rule, reach PostGroupService::attachMedia() with the string key
    // 'a' used directly as the pivot's unsigned-integer `order`, and
    // blow up as an uncaught QueryException instead of a clean 422.
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->create();

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => ['a' => $media->id]]]],
        ],
    ]);

    $response->assertStatus(422);
    expect(PostGroup::count())->toBe(0);
    expect(Post::count())->toBe(0);
});

it('rejects duplicate media ids within one segment instead of silently reordering them', function () {
    // Review Minor 2: [A, B, A] used to be silently rewritten to [B, A]
    // by attachMedia()'s id-keyed pivot array -- a media_post row can
    // only exist once per (media_id, post_id) pair regardless, so the
    // duplicate quietly discarded the client's stated order. Ordering is
    // content, so this must now be a 422, not a guess.
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->count(2)->create();

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$media[0]->id, $media[1]->id, $media[0]->id]]]],
        ],
    ]);

    $response->assertStatus(422);
    // The duplicate check is a Closure rule scoped to this one segment's
    // own media_ids array (see ValidatesPostChains' docblock for why the
    // built-in 'distinct' rule can't be used here) -- the error lands on
    // the array field itself.
    $response->assertJsonValidationErrors(['posts.0.value.0.media_ids']);
    expect(PostGroup::count())->toBe(0);
});

it('allows the same media id reused across two segments of one thread', function () {
    // Review round 2: the built-in 'distinct' rule's request-wide scope
    // used to reject this -- a media_post row is unique per (media_id,
    // post_id), so two distinct Post rows referencing the same Media
    // collide on nothing and must be legal.
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->create();

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            [
                'integration_id' => $integration->id,
                'value' => [
                    ['content' => 'root', 'media_ids' => [$media->id]],
                    ['content' => 'reply', 'media_ids' => [$media->id]],
                ],
            ],
        ],
    ]);

    $response->assertCreated();
    $posts = Post::query()->orderBy('order')->get();
    expect($posts)->toHaveCount(2);
    expect($posts[0]->media()->pluck('media.id')->all())->toBe([$media->id]);
    expect($posts[1]->media()->pluck('media.id')->all())->toBe([$media->id]);
});

it('allows the same media id reused across two different channels in one request', function () {
    // Review round 2: this is the primary reason a shared media library
    // exists -- posting the same image to two channels in one request.
    $org = Organization::factory()->create();
    actingAsMember($org);
    $telegram = Integration::factory()->for($org)->create(['provider_identifier' => 'telegram']);
    $bluesky = Integration::factory()->for($org)->create(['provider_identifier' => 'bluesky']);
    // An explicit size: MediaFactory's default is comfortably over
    // Bluesky's 1MB cap, and this test is about sharing one media id
    // across channels, not about the size rule.
    $media = Media::factory()->for($org)->create(['mime_type' => 'image/jpeg', 'size_bytes' => 1024]);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $telegram->id, 'value' => [['content' => 'A', 'media_ids' => [$media->id]]]],
            ['integration_id' => $bluesky->id, 'value' => [['content' => 'B', 'media_ids' => [$media->id]]]],
        ],
    ]);

    $response->assertCreated();
    $posts = Post::query()->get();
    expect($posts)->toHaveCount(2);
    foreach ($posts as $post) {
        expect($post->media()->pluck('media.id')->all())->toBe([$media->id]);
    }
});

it('rejects a media id belonging to another organization with 422', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $otherOrg = Organization::factory()->create();
    $foreignMedia = Media::factory()->for($otherOrg)->create();

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$foreignMedia->id]]]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.value.0.media_ids.0']);
    expect(PostGroup::count())->toBe(0);
    expect(Post::count())->toBe(0);
});

it('rejects a media id that does not exist at all with 422', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [(string) Str::ulid()]]]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.value.0.media_ids.0']);
    expect(PostGroup::count())->toBe(0);
});

it('rejects 5 images for Bluesky, which caps at 4', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'bluesky']);
    $media = Media::factory()->for($org)->count(5)->create(['mime_type' => 'image/jpeg']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'too many', 'media_ids' => $media->pluck('id')->all()]]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.value.0.media_ids']);
    expect(PostGroup::count())->toBe(0);
});

it('accepts a post with valid, owned media within provider limits', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'bluesky']);
    // Bluesky: maxImages 4, maxFileSizeBytes 1MB -- comfortably inside
    // both, and MediaFactory always sets a real size_bytes, proving
    // file_size_unknown is reachable-but-not-triggered by real media.
    $media = Media::factory()->for($org)->count(2)->create(['mime_type' => 'image/jpeg', 'size_bytes' => 1024]);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'valid media', 'media_ids' => $media->pluck('id')->all()]]],
        ],
    ]);

    $response->assertCreated();
    expect(Post::sole()->media()->count())->toBe(2);
});

it('rejects an oversized file against the provider file-size limit, using the stored size_bytes', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'bluesky']);
    // Bluesky's cap is 1MB.
    $media = Media::factory()->for($org)->create(['mime_type' => 'image/jpeg', 'size_bytes' => 1_000_001]);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'big file', 'media_ids' => [$media->id]]]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.value.0.media_ids']);
    expect(PostGroup::count())->toBe(0);
});

it('rejects an unsupported mime type for the target provider', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    // Bluesky only allows jpeg/png/webp.
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'bluesky']);
    $media = Media::factory()->for($org)->create(['mime_type' => 'image/gif']);

    $response = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'gif', 'media_ids' => [$media->id]]]],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['posts.0.value.0.media_ids']);
});

it('preserves media order across a PUT replace', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->count(2)->create();

    $create = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$media[0]->id]]]],
        ],
    ])->assertCreated();

    $group = PostGroup::find($create->json('id'));

    $reversed = [$media[1]->id, $media[0]->id];
    $update = $this->putJson("/api/posts/{$group->id}", [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A updated', 'media_ids' => $reversed]]],
        ],
    ]);

    $update->assertOk();
    $update->assertJsonPath('posts.0.value.0.media_ids', $reversed);
});

it('preserves media and order when duplicating a group', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->count(2)->create();

    $create = $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$media[1]->id, $media[0]->id]]]],
        ],
    ])->assertCreated();

    $group = PostGroup::find($create->json('id'));

    $duplicate = $this->postJson("/api/posts/{$group->id}/duplicate")->assertCreated();

    $duplicate->assertJsonPath('posts.0.value.0.media_ids', [$media[1]->id, $media[0]->id]);
});

it('409s deleting media attached to a real, unpublished post created through the API', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->create();

    $this->postJson('/api/posts', [
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$media->id]]]],
        ],
    ])->assertCreated();

    $this->deleteJson("/api/media/{$media->id}")->assertStatus(409);
    expect(Media::query()->find($media->id))->not->toBeNull();
});

it('allows deleting media whose only real, API-created attachment is published', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->create(['path' => 'org/x/p.jpg', 'thumbnail_path' => null]);
    Storage::disk('media')->put($media->path, 'x');

    $create = $this->postJson('/api/posts', [
        'type' => 'now',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$media->id]]]],
        ],
    ])->assertCreated();

    $post = Post::sole();
    // type=now creates the post in QUEUE, not PUBLISHED (Task 30's
    // publish job owns that transition, out of scope here) -- force the
    // state directly to exercise the "already published" branch of the
    // 409 check without needing the publish pipeline.
    $post->update(['state' => PostState::PUBLISHED]);

    $this->deleteJson("/api/media/{$media->id}")->assertNoContent();
    expect(Media::query()->find($media->id))->toBeNull();
});

/*
 * Final review, batch 4 (tenancy). `media()->attach()` and
 * `tags()->sync()` write the pivot through a BelongsToMany, which never
 * instantiates the related model — so the `organization` global scope is
 * never consulted, and neither pivot table carries an `organization_id`
 * of its own. Both were safe only because every call site is fed ids the
 * FormRequest already filtered with
 * `Rule::exists()->where('organization_id', …)`.
 *
 * "Safe because of where the guard sits" is not the same as safe. The
 * blast radius is specific and known: `PublishService` re-loads that same
 * pivot with `withoutGlobalScope('organization')`, so a foreign row
 * reaching it means another tenant's private file is uploaded to a social
 * network. These two tests call the SERVICE directly, which is exactly
 * the shape of any future caller that is not an HTTP request — a console
 * command, a job, an importer.
 */
it('refuses to attach media belonging to another organization, called service-directly', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $foreign = Media::factory()->for(Organization::factory()->create())->create();
    // The service reads the tenant from the context the `organization`
    // middleware would have set; there is no HTTP request here to set it.
    OrganizationContext::set($org);

    expect(fn () => app(PostGroupService::class)->create([
        'type' => 'draft',
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A', 'media_ids' => [$foreign->id]]]],
        ],
    ]))->toThrow(HttpException::class);

    expect(DB::table('media_post')->where('media_id', $foreign->id)->count())->toBe(0);
});

it('refuses to sync a tag belonging to another organization, called service-directly', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $foreignTag = Tag::factory()->for(Organization::factory()->create())->create();
    OrganizationContext::set($org);

    expect(fn () => app(PostGroupService::class)->create([
        'type' => 'draft',
        'tags' => [$foreignTag->id],
        'posts' => [
            ['integration_id' => $integration->id, 'value' => [['content' => 'A']]],
        ],
    ]))->toThrow(HttpException::class);

    expect(DB::table('post_group_tag')->where('tag_id', $foreignTag->id)->count())->toBe(0);
});
