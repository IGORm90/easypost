<?php

use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;

/*
 * Horizon renders tags in a web UI and lets an operator search and monitor
 * by them, so they carry ids only — never names, tokens or post content.
 * `provider:{id}` is what makes "did anything fail on one channel type"
 * a tag search instead of a log dig. A group fans out to several channels,
 * so it can carry several provider tags, each once.
 */
/** @param  list<string>  $providers */
function groupOnProviders(Organization $org, array $providers): PostGroup
{
    $group = PostGroup::factory()->create(['organization_id' => $org->id]);

    foreach ($providers as $i => $provider) {
        $integration = Integration::factory()->create([
            'organization_id' => $org->id,
            'provider_identifier' => $provider,
        ]);
        Post::factory()->create([
            'post_group_id' => $group->id,
            'organization_id' => $org->id,
            'integration_id' => $integration->id,
            'order' => $i,
        ]);
    }

    return $group;
}

it('tags a publish job with its organization, group and provider', function () {
    $org = Organization::factory()->create();
    $group = groupOnProviders($org, ['fake']);

    expect((new PublishPostJob($group->id, 1))->tags())->toEqualCanonicalizing([
        "organization:{$org->id}",
        "group:{$group->id}",
        'provider:fake',
    ]);
});

it('adds one provider tag per distinct provider in the group', function () {
    $org = Organization::factory()->create();
    $group = groupOnProviders($org, ['fake', 'fake', 'fake-b']);

    $tags = (new PublishPostJob($group->id, 1))->tags();

    expect(array_values(array_filter($tags, fn ($t) => str_starts_with($t, 'provider:'))))
        ->toEqualCanonicalizing(['provider:fake', 'provider:fake-b']);
});

it('still tags a group with no posts, without a provider tag', function () {
    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create(['organization_id' => $org->id]);

    expect((new PublishPostJob($group->id, 1))->tags())->toEqualCanonicalizing([
        "organization:{$org->id}",
        "group:{$group->id}",
    ]);
});
