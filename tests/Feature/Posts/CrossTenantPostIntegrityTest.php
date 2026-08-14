<?php

use App\Models\Integration;
use App\Models\Organization;
use App\Models\PostGroup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The database's own answer to "can a post point at another tenant's
 * channel" — asked below the application entirely, with a raw INSERT, so
 * no service, FormRequest, policy or global scope is involved. Final
 * review, batch 4 (tenancy minors).
 *
 * The equivalent guarantee for a post's GROUP has existed since
 * `create_posts_table` (`posts_organization_matches_group_fk`). This is
 * the same statement for its channel, which was recorded as blocked on
 * `integrations` lacking a `(organization_id, id)` unique key.
 */
it('refuses a post whose channel belongs to another organization', function () {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();

    $group = PostGroup::factory()->for($mine)->create();
    $foreignChannel = Integration::factory()->for($theirs)->create(['provider_identifier' => 'fake']);

    expect(fn () => DB::table('posts')->insert([
        'id' => (string) Str::ulid(),
        'post_group_id' => $group->id,
        'organization_id' => $mine->id,
        'integration_id' => $foreignChannel->id,
        'order' => 0,
        'content' => 'content for a channel that is not ours',
        'state' => 'DRAFT',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('still accepts a post whose channel belongs to the same organization', function () {
    // The other half of the constraint: a rule that rejects everything
    // would pass the test above and break the product.
    $org = Organization::factory()->create();
    $group = PostGroup::factory()->for($org)->create();
    $channel = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    DB::table('posts')->insert([
        'id' => (string) Str::ulid(),
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $channel->id,
        'order' => 0,
        'content' => 'ours',
        'state' => 'DRAFT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('posts')->where('post_group_id', $group->id)->count())->toBe(1);
});

it('keeps serving a post whose channel was disconnected, since soft deletes leave the row', function () {
    // `integrations` is soft-deleted, so the FK added here must not turn
    // an everyday disconnect into a constraint violation.
    $org = Organization::factory()->create();
    $group = PostGroup::factory()->for($org)->create();
    $channel = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    DB::table('posts')->insert([
        'id' => (string) Str::ulid(),
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $channel->id,
        'order' => 0,
        'content' => 'ours',
        'state' => 'DRAFT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $channel->delete();

    expect(DB::table('posts')->where('integration_id', $channel->id)->count())->toBe(1);
});
