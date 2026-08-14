<?php

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Signature;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Task 18: database-level invariants the `posts`/`post_groups` schema is
 * required to enforce directly, not merely leave to application code —
 * per the task brief's "Required beyond the brief" section. Every test
 * here drives real SQL against the real Postgres instance (RefreshDatabase,
 * wired for the whole Feature suite in tests/Pest.php); none of them
 * mock or bypass the database.
 */

it('rejects a post whose organization_id disagrees with its post_group_id', function () {
    $group = PostGroup::factory()->create();
    $otherOrg = Organization::factory()->create();
    $integration = Integration::factory()->create(['organization_id' => $group->organization_id]);

    expect(fn () => DB::table('posts')->insert([
        'id' => (string) Str::ulid(),
        'post_group_id' => $group->id,
        // Deliberately mismatched — must be rejected by
        // posts_organization_matches_group_fk, not merely by an
        // application-level check.
        'organization_id' => $otherOrg->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'x',
        'state' => 'DRAFT',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'posts_organization_matches_group_fk');
});

it('rejects a child post that belongs to a different post_group than its parent', function () {
    $parent = Post::factory()->create();
    $otherGroup = PostGroup::factory()->create(['organization_id' => $parent->organization_id]);

    expect(fn () => DB::table('posts')->insert([
        'id' => (string) Str::ulid(),
        // Different group than $parent, same integration.
        'post_group_id' => $otherGroup->id,
        'organization_id' => $otherGroup->organization_id,
        'integration_id' => $parent->integration_id,
        'parent_post_id' => $parent->id,
        'order' => 0,
        'content' => 'x',
        'state' => 'DRAFT',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'posts_parent_same_group_and_integration_fk');
});

it('rejects a child post that belongs to a different integration than its parent', function () {
    $parent = Post::factory()->create();
    $otherIntegration = Integration::factory()->create(['organization_id' => $parent->organization_id]);

    expect(fn () => DB::table('posts')->insert([
        'id' => (string) Str::ulid(),
        'post_group_id' => $parent->post_group_id,
        'organization_id' => $parent->organization_id,
        // Different integration than $parent, same group.
        'integration_id' => $otherIntegration->id,
        'parent_post_id' => $parent->id,
        'order' => 1,
        'content' => 'x',
        'state' => 'DRAFT',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'posts_parent_same_group_and_integration_fk');
});

it('allows a child post that shares its parent post_group_id and integration_id', function () {
    $parent = Post::factory()->create(['order' => 0]);

    $child = Post::factory()->create([
        'post_group_id' => $parent->post_group_id,
        'organization_id' => $parent->organization_id,
        'integration_id' => $parent->integration_id,
        'parent_post_id' => $parent->id,
        'order' => 1,
    ]);

    // withoutGlobalScope('organization'): no OrganizationContext is set in
    // this test (same reasoning as every other withoutGlobalScope() call
    // in this file) — the relation itself is exercised, only the tenant
    // scope is bypassed to observe it without acting as a member first.
    expect($child->parent_post_id)->toBe($parent->id)
        ->and($parent->fresh()->children()->withoutGlobalScope('organization')->pluck('id')->all())->toBe([$child->id]);
});

it('rejects two posts in the same channel chain claiming the same order', function () {
    $first = Post::factory()->create(['order' => 0]);

    expect(fn () => Post::factory()->create([
        'post_group_id' => $first->post_group_id,
        'organization_id' => $first->organization_id,
        'integration_id' => $first->integration_id,
        'order' => 0,
    ]))->toThrow(QueryException::class, 'posts_chain_order_unique');
});

it('allows the same order value in two different chains', function () {
    $a = Post::factory()->create(['order' => 0]);
    $b = Post::factory()->create(['order' => 0]);

    expect($a->id)->not->toBe($b->id);
});

it('never lets release_id be set and then cleared', function () {
    $post = Post::factory()->create(['release_id' => 'release-1']);

    expect(fn () => DB::table('posts')->where('id', $post->id)->update(['release_id' => null]))
        ->toThrow(QueryException::class, 'release_id is immutable once set');
});

it('never lets release_id be set and then changed to a different value', function () {
    $post = Post::factory()->create(['release_id' => 'release-1']);

    expect(fn () => DB::table('posts')->where('id', $post->id)->update(['release_id' => 'release-2']))
        ->toThrow(QueryException::class, 'release_id is immutable once set');
});

it('raises the immutability violation with its documented, stable SQLSTATE', function () {
    $post = Post::factory()->create(['release_id' => 'release-1']);

    try {
        DB::table('posts')->where('id', $post->id)->update(['release_id' => 'release-2']);
        $this->fail('Expected a QueryException.');
    } catch (QueryException $e) {
        // Task 30 is expected to detect this failure via the SQLSTATE
        // (Post::RELEASE_ID_IMMUTABLE_SQLSTATE), not by string-matching
        // the human-readable message — this proves the two actually
        // agree, not just that a query exception of SOME kind was thrown.
        expect($e->getCode())->toBe(Post::RELEASE_ID_IMMUTABLE_SQLSTATE);
    }
});

it('allows release_id to be set once from null, and other columns to keep changing after that', function () {
    $post = Post::factory()->create(['release_id' => null]);

    $post->update(['release_id' => 'release-1']);
    expect($post->fresh()->release_id)->toBe('release-1');

    // Setting release_id to the SAME value again (no real change) must
    // not trip the immutability trigger — only an actual change is
    // forbidden.
    $post->update(['release_id' => 'release-1', 'error' => 'transient retry noise']);
    expect($post->fresh()->release_id)->toBe('release-1')
        ->and($post->fresh()->error)->toBe('transient retry noise');
});

it('allows multiple post groups to simultaneously have no share_token', function () {
    $a = PostGroup::factory()->create(['share_token' => null]);
    $b = PostGroup::factory()->create(['share_token' => null]);

    expect($a->share_token)->toBeNull()->and($b->share_token)->toBeNull();
});

it('rejects two post groups with the same non-null share_token', function () {
    $token = Str::random(40);
    PostGroup::factory()->create(['share_token' => $token]);

    expect(fn () => PostGroup::factory()->create(['share_token' => $token]))
        ->toThrow(QueryException::class);
});

it('rejects a second default signature for the same organization', function () {
    $org = Organization::factory()->create();
    Signature::factory()->for($org)->default()->create();

    expect(fn () => Signature::factory()->for($org)->default()->create())
        ->toThrow(QueryException::class, 'signatures_one_default_per_org');
});

it('allows any number of non-default signatures for the same organization', function () {
    $org = Organization::factory()->create();
    Signature::factory()->for($org)->create();
    Signature::factory()->for($org)->create();

    expect(Signature::withoutGlobalScope('organization')->where('organization_id', $org->id)->count())->toBe(2);
});

it('allows a different organization to also have its own default signature', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    Signature::factory()->for($orgA)->default()->create();
    Signature::factory()->for($orgB)->default()->create();

    expect(Signature::withoutGlobalScope('organization')->where('is_default', true)->count())->toBe(2);
});

it('rejects a duplicate tag name within the same organization', function () {
    $org = Organization::factory()->create();
    Tag::factory()->for($org)->create(['name' => 'launch']);

    expect(fn () => Tag::factory()->for($org)->create(['name' => 'launch']))
        ->toThrow(QueryException::class);
});

it('rejects a tag name that differs from an existing one only by case within the same organization', function () {
    // The exact pair the migration's comment names ("Launch" vs
    // "launch"): Postgres' default collation is case-sensitive, so a
    // plain unique(organization_id, name) constraint would NOT catch
    // this — only the functional index on lower(name) does.
    $org = Organization::factory()->create();
    Tag::factory()->for($org)->create(['name' => 'Launch']);

    expect(fn () => Tag::factory()->for($org)->create(['name' => 'launch']))
        ->toThrow(QueryException::class, 'tags_org_name_ci_unique');
});

it('allows the same tag name across two different organizations', function () {
    Tag::factory()->create(['name' => 'launch']);
    Tag::factory()->create(['name' => 'launch']);

    expect(Tag::withoutGlobalScope('organization')->where('name', 'launch')->count())->toBe(2);
});

it('cascades deleting a post_group to its posts', function () {
    $post = Post::factory()->create();
    $groupId = $post->post_group_id;

    PostGroup::withoutGlobalScope('organization')->find($groupId)->delete();

    expect(Post::withoutGlobalScope('organization')->where('id', $post->id)->exists())->toBeFalse();
});

it('cascades deleting a parent post to its children', function () {
    $parent = Post::factory()->create(['order' => 0]);
    $child = Post::factory()->create([
        'post_group_id' => $parent->post_group_id,
        'organization_id' => $parent->organization_id,
        'integration_id' => $parent->integration_id,
        'parent_post_id' => $parent->id,
        'order' => 1,
    ]);

    Post::withoutGlobalScope('organization')->find($parent->id)->delete();

    expect(Post::withoutGlobalScope('organization')->where('id', $child->id)->exists())->toBeFalse();
});

it('refuses to hard-delete an Integration that still has posts, including PUBLISHED ones', function () {
    $post = Post::factory()->create(['state' => 'PUBLISHED', 'release_id' => 'release-1']);
    $integrationId = $post->integration_id;

    // A real forceDelete(), not the ordinary soft-delete disconnect flow
    // — this is exactly the "future prune job / manual cleanup" path the
    // restrictOnDelete() change guards against.
    //
    // Wrapped in DB::transaction(): RefreshDatabase already has this
    // whole test running inside one outer transaction, and Postgres
    // aborts a transaction entirely on a failed statement — without a
    // SAVEPOINT (which nesting a DB::transaction() call inside an
    // already-open one gives for free, per Laravel's transaction manager),
    // the assertions below would themselves fail with "current
    // transaction is aborted" rather than actually observing anything.
    expect(fn () => DB::transaction(
        fn () => Integration::withoutGlobalScope('organization')->find($integrationId)->forceDelete()
    ))->toThrow(QueryException::class);

    // The post — and its release_id — must genuinely still be there
    // afterwards, not merely "the delete call raised something".
    $survived = Post::withoutGlobalScope('organization')->find($post->id);
    expect($survived)->not->toBeNull()
        ->and($survived->release_id)->toBe('release-1');
});

it('allows deleting an Integration that has no posts', function () {
    $integration = Integration::factory()->create();

    Integration::withoutGlobalScope('organization')->find($integration->id)->forceDelete();

    expect(Integration::withoutGlobalScope('organization')->where('id', $integration->id)->exists())->toBeFalse();
});

it('nulls out created_by instead of deleting the post group when its creator is removed', function () {
    $user = User::factory()->create();
    $group = PostGroup::factory()->create(['created_by' => $user->id]);

    $user->delete();

    $survived = PostGroup::withoutGlobalScope('organization')->find($group->id);
    expect($survived)->not->toBeNull()
        ->and($survived->created_by)->toBeNull();
});

it('does not cascade a deleted user onward to the group\'s posts and comments', function () {
    $user = User::factory()->create();
    $group = PostGroup::factory()->create(['created_by' => $user->id]);
    // The channel has to be named explicitly alongside the group.
    // PostFactory derives `integration_id` from the group it builds
    // ITSELF, so overriding only `post_group_id`/`organization_id` leaves
    // the post pointing at a channel in a third, unrelated organization —
    // which `posts_organization_matches_integration_fk` now rejects
    // outright. The constraint caught the fixture, not the behaviour
    // under test.
    $post = Post::factory()->create([
        'post_group_id' => $group->id,
        'organization_id' => $group->organization_id,
        'integration_id' => Integration::factory()->create(['organization_id' => $group->organization_id])->id,
    ]);

    $user->delete();

    expect(Post::withoutGlobalScope('organization')->where('id', $post->id)->exists())->toBeTrue();
});
