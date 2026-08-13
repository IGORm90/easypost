<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Task 28: attaching media to posts. media_ids in the POST/PUT
 * /api/posts payload must persist to the media_post pivot with order
 * preserved, PostResource must expose the richer {id,url,thumbnail_url,
 * mime,width,height} shape, and media_ids must be re-validated for
 * existence + organization ownership (unlike Task 20's shape-only
 * check) and checked against the target provider's ProviderCapabilities
 * through PostValidationService.
 */

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

it('rejects 5 images for Mastodon, which caps at 4', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'mastodon']);
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
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'mastodon']);
    // Mastodon: maxImages 4, maxFileSizeBytes 40MB -- comfortably inside
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
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'mastodon']);
    // Mastodon's cap is 40MB.
    $media = Media::factory()->for($org)->create(['mime_type' => 'image/jpeg', 'size_bytes' => 41 * 1024 * 1024]);

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
