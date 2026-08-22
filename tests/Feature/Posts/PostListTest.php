<?php

use App\Enums\PostState;
use App\Models\Comment;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Tag;
use App\Models\User;

/*
 * Block 2: GET /api/posts — the paginated list view the calendar grid
 * cannot be. Same tenant bar as GET /api/calendar (auth:sanctum +
 * organization, plain membership), same filter parameter names
 * (`customer`, `tags[]`) deliberately, plus `state` and pagination.
 *
 * Two of the cases below exist for reasons that are invisible from the
 * endpoint's shape and would otherwise be found in production:
 *
 *  - "pages do not overlap when every group shares one publish_date":
 *    `publish_date DESC` alone is not a total order, and equal
 *    timestamps are the NORMAL case here (publish-now stamps now() on
 *    every group it touches). Without a unique tiebreaker, a group can
 *    appear on both pages, or on neither.
 *  - "a dateless group does not lead the list": the column is nullable,
 *    and PostgreSQL sorts NULLs FIRST on DESC — so the one group with no
 *    date would head a list sorted newest-first.
 */

/** @return array{0: Organization, 1: Integration} */
function listOrg(): array
{
    $org = Organization::factory()->create();
    actingAsMember($org);

    return [$org, Integration::factory()->for($org)->create(['provider_identifier' => 'fake'])];
}

function listGroup(Organization $org, Integration $integration, PostState $state, ?string $date, string $content = 'Hello'): PostGroup
{
    $group = PostGroup::factory()->for($org)->create(['state' => $state, 'publish_date' => $date]);
    Post::factory()->for($group, 'group')->create([
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'content' => $content,
    ]);

    return $group;
}

it('lists every state when no filter is given, newest first', function () {
    [$org, $integration] = listOrg();

    $older = listGroup($org, $integration, PostState::DRAFT, now()->subDays(2)->toDateTimeString());
    $newer = listGroup($org, $integration, PostState::PUBLISHED, now()->subDay()->toDateTimeString());

    $response = $this->getJson('/api/posts');

    $response->assertOk();
    expect(collect((array) $response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id]);

    // `state=all` is the same request spelled out — the SPA sends it
    // explicitly when the "All" segment is selected.
    $explicit = $this->getJson('/api/posts?state=all');
    expect(collect((array) $explicit->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

it('filters by each post state', function (string $state) {
    [$org, $integration] = listOrg();

    $wanted = listGroup($org, $integration, PostState::from($state), now()->toDateTimeString());

    foreach (PostState::cases() as $case) {
        if ($case->value !== $state) {
            listGroup($org, $integration, $case, now()->toDateTimeString());
        }
    }

    $response = $this->getJson('/api/posts?state='.$state);

    $response->assertOk();
    expect(collect((array) $response->json('data'))->pluck('id')->all())->toBe([$wanted->id]);
})->with(['DRAFT', 'QUEUE', 'PUBLISHED', 'ERROR']);

it('rejects an unknown state instead of silently listing everything', function () {
    listOrg();

    $this->getJson('/api/posts?state=WHATEVER')->assertStatus(422);
});

it('rejects a per_page above the ceiling', function () {
    listOrg();

    $this->getJson('/api/posts?per_page=100000')->assertStatus(422);
    $this->getJson('/api/posts?per_page=0')->assertStatus(422);
});

it('honours a per_page within the ceiling', function () {
    [$org, $integration] = listOrg();

    foreach (range(1, 3) as $offset) {
        listGroup($org, $integration, PostState::QUEUE, now()->subDays($offset)->toDateTimeString());
    }

    $response = $this->getJson('/api/posts?per_page=2');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.last_page'))->toBe(2);
});

it('pages do not overlap when every group shares one publish_date', function () {
    [$org, $integration] = listOrg();

    $sameInstant = now()->toDateTimeString();
    $ids = collect(range(1, 6))
        ->map(fn () => listGroup($org, $integration, PostState::QUEUE, $sameInstant)->id)
        ->all();

    $first = collect((array) $this->getJson('/api/posts?per_page=3&page=1')->json('data'))->pluck('id');
    $second = collect((array) $this->getJson('/api/posts?per_page=3&page=2')->json('data'))->pluck('id');

    expect($first->intersect($second))->toBeEmpty()
        ->and($first->merge($second)->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
});

it('does not let a dateless group lead the list', function () {
    [$org, $integration] = listOrg();

    listGroup($org, $integration, PostState::DRAFT, null);
    $dated = listGroup($org, $integration, PostState::QUEUE, now()->toDateTimeString());

    $response = $this->getJson('/api/posts');

    $response->assertOk();
    expect($response->json('data.0.id'))->toBe($dated->id);
});

it('filters by tag and by customer, with the same parameter names the calendar uses', function () {
    [$org, $integration] = listOrg();
    $tag = Tag::factory()->for($org)->create();

    $tagged = listGroup($org, $integration, PostState::QUEUE, now()->toDateTimeString());
    $tagged->tags()->attach($tag);
    listGroup($org, $integration, PostState::QUEUE, now()->toDateTimeString());

    $byTag = $this->getJson('/api/posts?'.http_build_query(['tags' => [$tag->id]]));
    $byTag->assertOk();
    expect(collect((array) $byTag->json('data'))->pluck('id')->all())->toBe([$tagged->id]);

    $acme = Integration::factory()->for($org)->create(['provider_identifier' => 'fake', 'customer_name' => 'Acme']);
    $acmeGroup = listGroup($org, $acme, PostState::QUEUE, now()->toDateTimeString());

    $byCustomer = $this->getJson('/api/posts?customer=Acme');
    $byCustomer->assertOk();
    expect(collect((array) $byCustomer->json('data'))->pluck('id')->all())->toBe([$acmeGroup->id]);
});

it('does not widen the result when a tag id belongs to another organization', function () {
    [$org, $integration] = listOrg();
    listGroup($org, $integration, PostState::QUEUE, now()->toDateTimeString());

    $foreignTag = Tag::factory()->for(Organization::factory()->create())->create();

    $response = $this->getJson('/api/posts?'.http_build_query(['tags' => [$foreignTag->id]]));

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('does not leak groups from other organizations', function () {
    [$org, $integration] = listOrg();
    $mine = listGroup($org, $integration, PostState::QUEUE, now()->toDateTimeString());

    $otherOrg = Organization::factory()->create();
    $otherIntegration = Integration::factory()->for($otherOrg)->create(['provider_identifier' => 'fake']);
    listGroup($otherOrg, $otherIntegration, PostState::QUEUE, now()->toDateTimeString());

    $response = $this->getJson('/api/posts');

    $response->assertOk();
    expect(collect((array) $response->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

it('carries a comment count and a trimmed preview, never the full segment content', function () {
    [$org, $integration] = listOrg();

    $long = str_repeat('a', 500);
    $group = listGroup($org, $integration, PostState::QUEUE, now()->toDateTimeString(), $long);

    $user = User::factory()->create();
    // `comments` has no organization_id of its own — see Comment's
    // docblock; tenancy is inherited through PostGroup.
    Comment::factory()->count(2)->create([
        'post_group_id' => $group->id,
        'user_id' => $user->id,
    ]);

    $response = $this->getJson('/api/posts');

    $response->assertOk();
    $entry = $response->json('data.0');

    expect($entry['comments_count'])->toBe(2)
        ->and(mb_strlen((string) $entry['preview']))->toBeLessThan(mb_strlen($long))
        ->and($entry)->not->toHaveKey('posts')
        ->and(json_encode($entry))->not->toContain($long);
});

it('reports each channel once even when a thread has several segments on it', function () {
    [$org, $integration] = listOrg();

    // The root is captured from the insert rather than re-read through
    // $group->posts(): Post carries the organization global scope, and
    // OrganizationContext is only set inside a request, not here.
    $group = PostGroup::factory()->for($org)->create(['state' => PostState::QUEUE, 'publish_date' => now()]);
    $root = Post::factory()->for($group, 'group')->create([
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'content' => 'root',
    ]);
    Post::factory()->for($group, 'group')->create([
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'parent_post_id' => $root->id,
        'order' => 1,
        'content' => 'tail',
    ]);

    $response = $this->getJson('/api/posts');

    $response->assertOk();
    expect($response->json('data.0.channels'))->toHaveCount(1)
        ->and($response->json('data.0.channels.0.id'))->toBe($integration->id)
        // The preview is the FIRST segment, the one the calendar card shows too.
        ->and($response->json('data.0.preview'))->toBe('root');
});

it('requires a session and an organization', function () {
    $this->getJson('/api/posts')->assertStatus(401);
});
