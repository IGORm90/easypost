<?php

use App\Enums\PostState;
use App\Enums\RepeatUnit;
use App\Jobs\PublishPostJob;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\RecurrencePausedNotification;
use App\Services\Posts\PublishService;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Block 4: the successor of a recurring post is created only after its
 * predecessor has actually published — never up front.
 *
 * Runs with NO OrganizationContext throughout, exactly like
 * PublishPostJobTest and exactly like production: this code path only
 * ever executes inside a queue worker, where the `organization` global
 * scope fails CLOSED to zero rows. A test that leaves ambient context
 * set would prove nothing about the path that actually runs — it would
 * pass even if RecurrenceService read the source group's segments
 * through the scope and copied nothing at all.
 */

beforeEach(function () {
    OrganizationContext::clear();
    Queue::fake();
});

afterEach(fn () => OrganizationContext::clear());

/**
 * @param  array<string, mixed>  $attributes
 */
function repeatingQueuedGroup(Organization $org, array $attributes = []): PostGroup
{
    return PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now(),
        'repeat_unit' => RepeatUnit::DAY,
        'repeat_interval' => 2,
        'repeat_until' => now()->addMonth(),
        ...$attributes,
    ]);
}

it('creates the next occurrence once the current one publishes', function () {
    fakeProvider();
    $org = Organization::factory()->create();
    $group = repeatingQueuedGroup($org);
    publishableChannel($org, $group, ['every other day']);

    app(PublishService::class)->publishGroup($group->id);

    $next = PostGroup::withoutGlobalScope('organization')->whereKeyNot($group->id)->sole();

    // Durable-scheduling rewrite: the successor lands on SCHEDULED, not
    // QUEUE — and nothing is dispatched at spawn time at all, however
    // far out its date is. DueDispatchService claims it later, whenever
    // it is actually due — see spawnRecurrence()'s docblock.
    // Durable-scheduling rewrite (Task 6): spawnRecurrence() jitters the
    // computed date by up to config('publishing.jitter_seconds') — the
    // exact instant is JitterTest's job; here it only needs to stay
    // within that window of the un-jittered target.
    $expectedDate = $group->publish_date->copy()->addDays(2);
    $jitterWindow = (int) config('publishing.jitter_seconds');

    expect($group->fresh()->state)->toBe(PostState::PUBLISHED)
        ->and($next->state)->toBe(PostState::SCHEDULED)
        ->and(abs($next->publish_date->diffInSeconds($expectedDate, false)))->toBeLessThanOrEqual($jitterWindow)
        // The rule travels with the occurrence, or the series stops after one hop.
        ->and($next->repeat_unit)->toBe(RepeatUnit::DAY)
        ->and($next->repeat_interval)->toBe(2)
        // Points at the ROOT of the series, not at its predecessor.
        ->and($next->repeat_parent_id)->toBe($group->id)
        ->and($next->repeat_spawned_at)->toBeNull()
        // None of the predecessor's publish history comes along.
        ->and($next->share_token)->toBeNull()
        ->and($next->error)->toBeNull();

    Queue::assertNotPushed(PublishPostJob::class);
});

it('points every later occurrence at the first post of the series', function () {
    fakeProvider();
    $org = Organization::factory()->create();
    $root = repeatingQueuedGroup($org);
    publishableChannel($org, $root, ['first']);

    app(PublishService::class)->publishGroup($root->id);
    $second = PostGroup::withoutGlobalScope('organization')->whereKeyNot($root->id)->sole();

    // Durable-scheduling rewrite: a freshly spawned successor lands on
    // SCHEDULED, not QUEUE (see RecurrenceService/PostGroupService::spawnRecurrence()'s
    // docblock) — nothing is dispatched until DueDispatchService claims
    // it. Force it straight into QUEUE here, simulating that claim
    // directly (group AND its posts — the dispatcher always flips both
    // atomically, see PostGroupLifecycle::claimDue()): this test's
    // focus is the repeat_parent_id chain across hops, not the claim
    // mechanics DispatchDuePostsTest already covers.
    $second->forceFill(['publish_date' => now()])->save();
    DB::table('post_groups')->where('id', $second->id)->update(['state' => PostState::QUEUE->value]);
    DB::table('posts')->where('post_group_id', $second->id)->update(['state' => PostState::QUEUE->value]);
    app(PublishService::class)->publishGroup($second->fresh()->id);

    $third = PostGroup::withoutGlobalScope('organization')
        ->whereNotIn('id', [$root->id, $second->id])
        ->sole();

    expect($third->repeat_parent_id)->toBe($root->id);
});

it('copies the thread, its media and its tags into the next occurrence', function () {
    // A fake disk is enough here: this test is about what the successor
    // inherits, not about the bytes reaching a provider — that is
    // PublishMediaTest's job, and it deliberately uses a real disk.
    Storage::fake('media');
    fakeProvider();
    $org = Organization::factory()->create();
    $group = repeatingQueuedGroup($org);
    [$integration, $posts] = publishableChannel($org, $group, ['root', 'reply']);

    $media = Media::factory()->create(['organization_id' => $org->id]);
    // Real bytes on the fake disk: the publish pipeline reads the file
    // before it sends anything, and a missing one errors the whole group
    // — which would then spawn nothing, and this test would pass for
    // entirely the wrong reason.
    Storage::disk('media')->put($media->path, 'not really a jpeg');
    $posts[0]->media()->attach([$media->id => ['order' => 0]]);
    $posts[0]->forceFill(['settings' => ['some_channel_option' => true]])->save();

    $tag = Tag::factory()->create(['organization_id' => $org->id]);
    $group->tags()->attach($tag->id);

    app(PublishService::class)->publishGroup($group->id);

    $next = PostGroup::withoutGlobalScope('organization')->whereKeyNot($group->id)->sole();
    $copied = $next->posts()->withoutGlobalScope('organization')->orderBy('order')->get();

    expect($copied)->toHaveCount(2)
        ->and($copied[0]->content)->toBe('root')
        ->and($copied[0]->integration_id)->toBe($integration->id)
        ->and($copied[0]->settings)->toBe(['some_channel_option' => true])
        ->and($copied[0]->state)->toBe(PostState::SCHEDULED)
        // A fresh chain, not a second claim on the release the network already has.
        ->and($copied[0]->release_id)->toBeNull()
        ->and($copied[1]->content)->toBe('reply')
        // The thread is re-linked to the COPIES, never left pointing at the originals.
        ->and($copied[1]->parent_post_id)->toBe($copied[0]->id)
        ->and($copied[0]->media()->withoutGlobalScope('organization')->pluck('media.id')->all())->toBe([$media->id])
        ->and($next->tags()->withoutGlobalScope('organization')->pluck('tags.id')->all())->toBe([$tag->id]);
});

it('stops the series when the next occurrence would fall past repeat_until', function () {
    fakeProvider();
    $org = Organization::factory()->create();
    $group = repeatingQueuedGroup($org, ['repeat_until' => now()->addDay()]);
    publishableChannel($org, $group, ['last one']);

    app(PublishService::class)->publishGroup($group->id);

    expect(PostGroup::withoutGlobalScope('organization')->count())->toBe(1);
});

it('creates no successor for a group that does not repeat', function () {
    fakeProvider();
    $org = Organization::factory()->create();
    $group = queuedGroup($org);
    publishableChannel($org, $group, ['one-off']);

    app(PublishService::class)->publishGroup($group->id);

    expect(PostGroup::withoutGlobalScope('organization')->count())->toBe(1);
});

it('creates no successor when the publish failed', function () {
    // A failed occurrence stops the series rather than marching on
    // silently: the author already gets PostFailedNotification, and a
    // manual retry that succeeds resumes the series through the normal
    // path, because `repeat_spawned_at` is still null.
    fakeProvider()->failNextPostPermanently('the provider refused');
    $org = Organization::factory()->create();
    $group = repeatingQueuedGroup($org);
    publishableChannel($org, $group, ['doomed']);

    app(PublishService::class)->publishGroup($group->id);

    expect($group->fresh()->state)->toBe(PostState::ERROR)
        ->and(PostGroup::withoutGlobalScope('organization')->count())->toBe(1);
});

it('creates exactly one successor however many times the publish job runs', function () {
    // PublishPostJob is re-executed as a matter of course (Horizon
    // retry, Redis redelivery). PUBLISHED being terminal stops the
    // second run from publishing anything twice — it does NOT stop it
    // from spawning twice, which is what `repeat_spawned_at` is for.
    fakeProvider();
    $org = Organization::factory()->create();
    $group = repeatingQueuedGroup($org);
    publishableChannel($org, $group, ['once']);

    app(PublishService::class)->publishGroup($group->id);
    app(PublishService::class)->publishGroup($group->id);
    (new PublishPostJob($group->id, $group->queue_version))->handle(app(PublishService::class));

    expect(PostGroup::withoutGlobalScope('organization')->count())->toBe(2);
});

it('pauses the series and warns the author when the monthly quota is exhausted', function () {
    Notification::fake();
    fakeProvider();

    $author = User::factory()->create();
    $org = Organization::factory()->create(['plan' => 'free']);
    // One post per month, and the predecessor already used it: the
    // successor is what runs out of quota.
    config()->set('plans.free.limits.posts_per_month', 1);

    $group = repeatingQueuedGroup($org, ['created_by' => $author->id]);
    publishableChannel($org, $group, ['the last one this month']);

    app(PublishService::class)->publishGroup($group->id);

    expect(PostGroup::withoutGlobalScope('organization')->count())->toBe(1)
        // The publish itself succeeded — the quota belongs to the
        // successor, not to the post that already went out.
        ->and($group->fresh()->state)->toBe(PostState::PUBLISHED)
        // Claimed anyway: the series is over, and re-trying it on every
        // redelivery would just mail the author again.
        ->and($group->fresh()->repeat_spawned_at)->not->toBeNull();

    Notification::assertSentTo($author, RecurrencePausedNotification::class);
});

it('never lets a failure to spawn break the publish that already happened', function () {
    // The network has the post by this point. Anything thrown from here
    // would send PublishPostJob down failed() -> abandonGroup() and
    // record a failure that did not happen.
    fakeProvider();
    $org = Organization::factory()->create();
    $group = repeatingQueuedGroup($org);
    publishableChannel($org, $group, ['fragile']);

    // A recurrence whose unit is not one the enum knows: reading it back
    // out of the database throws inside the cast, which is as close to
    // "an unforeseen error mid-spawn" as this suite can arrange honestly.
    PostGroup::withoutGlobalScope('organization')->whereKey($group->id)->update(['repeat_unit' => 'FORTNIGHT']);

    app(PublishService::class)->publishGroup($group->id);

    expect($group->fresh(['posts'])->state)->toBe(PostState::PUBLISHED);
});
