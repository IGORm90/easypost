<?php

use App\Enums\OrganizationRole;
use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * Task 27: GET /api/media (library listing) and DELETE /api/media/{id}.
 */

// --- GET /api/media --------------------------------------------------------

it('does not list media from another organization', function () {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    actingAsMember($org);

    Media::factory()->create(['organization_id' => $org->id, 'original_filename' => 'mine.jpg']);
    Media::factory()->create(['organization_id' => $otherOrg->id, 'original_filename' => 'not-mine.jpg']);

    $response = $this->getJson('/api/media')->assertOk();

    $filenames = array_column((array) $response->json('data'), 'original_filename');
    expect($filenames)->toContain('mine.jpg')
        ->and($filenames)->not->toContain('not-mine.jpg');
});

it('paginates 24 per page', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    Media::factory()->count(30)->create(['organization_id' => $org->id]);

    $response = $this->getJson('/api/media')->assertOk();

    expect($response->json('data'))->toHaveCount(24)
        ->and($response->json('meta.total'))->toBe(30)
        ->and($response->json('meta.per_page'))->toBe(24);
});

it('filters by search against the original filename, scoped to the current organization', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    Media::factory()->create(['organization_id' => $org->id, 'original_filename' => 'summer-banner.jpg']);
    Media::factory()->create(['organization_id' => $org->id, 'original_filename' => 'winter-banner.jpg']);
    Media::factory()->create(['organization_id' => $org->id, 'original_filename' => 'logo.png']);

    $response = $this->getJson('/api/media?search=banner')->assertOk();

    $filenames = array_column((array) $response->json('data'), 'original_filename');
    sort($filenames);
    expect($filenames)->toBe(['summer-banner.jpg', 'winter-banner.jpg']);
});

it('treats search as a literal substring, not an ILIKE wildcard pattern', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    Media::factory()->create(['organization_id' => $org->id, 'original_filename' => 'a100percent.jpg']);
    Media::factory()->create(['organization_id' => $org->id, 'original_filename' => 'a%b.jpg']);

    // A literal '%' in the search term must only match a literal '%' in
    // filenames, not act as an ILIKE wildcard matching "a<anything>b".
    $response = $this->getJson('/api/media?'.http_build_query(['search' => 'a%b']))->assertOk();

    $filenames = array_column((array) $response->json('data'), 'original_filename');
    expect($filenames)->toBe(['a%b.jpg']);
});

// --- DELETE /api/media/{id} -------------------------------------------------

it('deletes media that is not attached to any post', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $media = Media::factory()->create([
        'organization_id' => $org->id,
        'path' => 'org/x/original.jpg',
        'thumbnail_path' => 'org/x/original_thumb.jpg',
    ]);
    Storage::disk('media')->put($media->path, 'x');
    Storage::disk('media')->put($media->thumbnail_path, 'x');

    $this->deleteJson("/api/media/{$media->id}")->assertNoContent();

    expect(Media::query()->find($media->id))->toBeNull();
    Storage::disk('media')->assertMissing($media->path);
});

it('returns 404 for media belonging to another organization', function () {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    actingAsMember($org);

    $media = Media::factory()->create(['organization_id' => $otherOrg->id]);

    $this->deleteJson("/api/media/{$media->id}")->assertNotFound();
});

it('409s when the media is attached to an unpublished post', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $media = Media::factory()->create(['organization_id' => $org->id]);
    $post = createPostInOrg($org, PostState::DRAFT);
    $media->posts()->attach($post);

    $this->deleteJson("/api/media/{$media->id}")->assertStatus(409);

    expect(Media::query()->find($media->id))->not->toBeNull();
});

it('409s when the unpublished post attached to the media was created by a different member of the same organization', function () {
    // Task 27 requirement: the 409 check must span the whole
    // organization, not only the deleting caller's own posts.
    $org = Organization::factory()->create();
    actingAsMember($org, 'ADMIN');
    $otherUser = User::factory()->create();
    $org->addMember($otherUser, OrganizationRole::USER);

    $media = Media::factory()->create(['organization_id' => $org->id]);
    $post = createPostInOrg($org, PostState::DRAFT, $otherUser);
    $media->posts()->attach($post);

    $this->deleteJson("/api/media/{$media->id}")->assertStatus(409);
});

it('allows deleting media whose only attached post is already published', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $media = Media::factory()->create(['organization_id' => $org->id, 'path' => 'org/x/p.jpg', 'thumbnail_path' => null]);
    Storage::disk('media')->put($media->path, 'x');
    $post = createPostInOrg($org, PostState::PUBLISHED);
    $media->posts()->attach($post);

    $this->deleteJson("/api/media/{$media->id}")->assertNoContent();

    expect(Media::query()->find($media->id))->toBeNull();
});

/**
 * Builds a Post (per-channel segment) in $org with the given state,
 * wiring its own PostGroup/Integration under the same organization —
 * PostFactory's default definition() builds its own group/integration,
 * so the overrides below replace them rather than compose with them.
 */
function createPostInOrg(Organization $org, PostState $state, ?User $author = null): Post
{
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'created_by' => $author?->id,
    ]);
    $integration = Integration::factory()->create(['organization_id' => $org->id]);

    return Post::factory()->create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'state' => $state,
    ]);
}
