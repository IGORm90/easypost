<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Tag;
use Illuminate\Support\Facades\Queue;

/*
 * `POST /api/posts/{group}/duplicate` had no test of any kind — found
 * while re-reviewing batch 4, because the ownership assertions added to
 * `attachMedia()`/`syncTags()` run on this path too and nothing here
 * would have noticed if they broke it.
 *
 * It is also one of the endpoints with no SPA caller (see
 * docs/ACCEPTANCE.md, "Backend built, no UI yet"), which is precisely why
 * it needs a test: nothing else exercises it, by hand or otherwise.
 */
beforeEach(fn () => Queue::fake());

function duplicateFixture(Organization $org): PostGroup
{
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);
    $media = Media::factory()->for($org)->count(2)->create();
    $tag = Tag::factory()->for($org)->create();

    $group = PostGroup::factory()->for($org)->create([
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDay(),
    ]);

    $root = Post::factory()->for($org)->for($group, 'group')->for($integration)->create([
        'content' => 'first segment',
        'order' => 0,
        'state' => PostState::QUEUE,
    ]);
    Post::factory()->for($org)->for($group, 'group')->for($integration)->create([
        'content' => 'second segment',
        'order' => 1,
        'parent_post_id' => $root->id,
        'state' => PostState::QUEUE,
    ]);

    $root->media()->attach([$media[1]->id => ['order' => 0], $media[0]->id => ['order' => 1]]);
    $group->tags()->sync([$tag->id]);

    return $group;
}

it('copies the content, the thread shape, the media order and the tags', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = duplicateFixture($org);

    $response = $this->postJson("/api/posts/{$group->id}/duplicate")->assertCreated();

    $copyId = $response->json('id');
    expect($copyId)->not->toBe($group->id);

    $copy = PostGroup::query()->with(['posts.media', 'tags'])->findOrFail($copyId);

    // A duplicate is a DRAFT, whatever the source was: copying a QUEUE
    // group as QUEUE would schedule a second publication nobody asked for.
    expect($copy->state)->toBe(PostState::DRAFT)
        ->and($copy->posts->pluck('content')->all())->toBe(['first segment', 'second segment'])
        ->and($copy->tags->pluck('id')->all())->toBe($group->tags->pluck('id')->all());

    // The chain is re-linked to the COPY's own root, never left pointing
    // at the source's segments.
    $copyRoot = $copy->posts->firstWhere('order', 0);
    $copyChild = $copy->posts->firstWhere('order', 1);
    expect($copyChild->parent_post_id)->toBe($copyRoot->id)
        ->and(array_intersect($copy->posts->pluck('id')->all(), $group->posts->pluck('id')->all()))->toBe([]);

    // Media order comes from the pivot, not from the media table's own
    // id order — the fixture attaches them deliberately reversed.
    expect($copyRoot->media->pluck('id')->all())
        ->toBe($group->fresh(['posts.media'])->posts->firstWhere('order', 0)->media->pluck('id')->all());

    // Nothing published: a copy is a draft, so no job is dispatched.
    Queue::assertNothingPushed();
});

it('leaves the source group untouched', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);
    $group = duplicateFixture($org);

    $this->postJson("/api/posts/{$group->id}/duplicate")->assertCreated();

    $source = $group->fresh(['posts.media', 'tags']);
    expect($source->state)->toBe(PostState::QUEUE)
        ->and($source->posts)->toHaveCount(2)
        ->and($source->tags)->toHaveCount(1)
        ->and($source->posts->firstWhere('order', 0)->media)->toHaveCount(2);
});

it("404s duplicating another organization's group", function () {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    actingAsMember($mine);
    $foreign = duplicateFixture($theirs);

    $this->postJson("/api/posts/{$foreign->id}/duplicate")->assertNotFound();

    // Asserted per-organization rather than as a total row count:
    // PostFactory eagerly builds a PostGroup of its own inside
    // definition() before `for()` overrides `post_group_id`, so the table
    // legitimately holds discarded fixture groups and a bare count() here
    // would be measuring the factory, not the endpoint.
    expect(PostGroup::withoutGlobalScope('organization')->where('organization_id', $mine->id)->count())->toBe(0)
        ->and(PostGroup::withoutGlobalScope('organization')->whereKey($foreign->id)->count())->toBe(1);
});

it('401s an unauthenticated caller', function () {
    $org = Organization::factory()->create();
    $group = duplicateFixture($org);

    $this->postJson("/api/posts/{$group->id}/duplicate")->assertUnauthorized();
});
