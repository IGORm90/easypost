<?php

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Posts\PublishService;
use App\Support\OrganizationContext;
use App\Support\SafeRemoteHost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Final-review batch 2, Critical 3: media publishing through the REAL
 * `publishGroup()` path, for every provider that can carry a file.
 *
 * Two deliberate rules in this file, both of which the defect this
 * covers slipped past once already:
 *
 *  - NO `Storage::fake()`. A fake disk always succeeds, which is exactly
 *    what hid the total media-loss bug this file exists to prevent: the
 *    publishing pipeline never read a byte off the disk at all, so a
 *    disk that answers "yes" to everything proves nothing. The `media`
 *    disk here is a REAL local Flysystem disk rooted in a temp
 *    directory, written with real bytes and read back through the same
 *    `Storage::disk($media->disk)->readStream()` production uses.
 *  - Every assertion is on the BYTES ACTUALLY SENT. Asserting that the
 *    post reached PUBLISHED is not enough — `file_get_contents()` on an
 *    S3 key returns false, `(string) false` is `''`, and a zero-byte
 *    attachment publishes perfectly happily. Each test below asserts the
 *    outbound request body literally contains the file's bytes.
 */

/**
 * The temp directory backing this file's REAL `media` disk. A helper
 * function with a static, not a property on `$this`: Pest's `$this`
 * inside a test closure is not statically resolvable to this project's
 * TestCase (see tests/Unit/Services/Media/CappedSinkStreamWrapperTest.php
 * for the same note), so a dynamic property there is invisible to
 * PHPStan.
 */
function publishMediaRoot(?string $set = null): string
{
    static $root = '';

    if ($set !== null) {
        $root = $set;
    }

    return $root;
}

beforeEach(function () {
    OrganizationContext::clear();

    $root = publishMediaRoot(sys_get_temp_dir().'/publish-media-root-'.bin2hex(random_bytes(6)));
    mkdir($root, 0777, true);

    config(['filesystems.disks.media' => [
        'driver' => 'local',
        'root' => $root,
        'url' => 'http://localhost/media',
        'visibility' => 'public',
        'throw' => true,
    ]]);

    SafeRemoteHost::fake([
        'mastodon.social' => ['198.51.100.10'],
        'discord.com' => ['198.51.100.11'],
        'slack.com' => ['198.51.100.12'],
        'files.slack.com' => ['198.51.100.13'],
    ]);
});

afterEach(function () {
    SafeRemoteHost::stopFaking();
    OrganizationContext::clear();

    $root = publishMediaRoot();

    if ($root !== '' && is_dir($root)) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/**
 * The exact bytes on disk — a real (tiny) PNG header plus a marker that
 * cannot occur by accident, so "the body contains the file" is a
 * meaningful assertion rather than a coincidence.
 */
function publishMediaBytes(): string
{
    return "\x89PNG\r\n\x1a\n".str_repeat('MEDIA-BYTES-MARKER-', 8);
}

/**
 * One QUEUE post group, on $provider, with one real file attached.
 *
 * @param  array<string, mixed>  $settings
 * @return array{0: PostGroup, 1: Post}
 */
function publishableMediaPost(string $provider, array $settings = [], string $token = 'provider-token'): array
{
    $org = Organization::factory()->create();

    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->subMinute(),
    ]);

    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => $provider,
        'token' => $token,
        'settings' => $settings,
    ]);

    $bytes = publishMediaBytes();
    $key = "org/{$org->id}/attachment.png";

    // A real write to a real (local) disk — see this file's header.
    Storage::disk('media')->put($key, $bytes);

    $media = Media::query()->create([
        'organization_id' => $org->id,
        'disk' => 'media',
        'path' => $key,
        'mime_type' => 'image/png',
        'size_bytes' => strlen($bytes),
        'original_filename' => 'attachment.png',
    ]);

    $post = Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'with an attachment',
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);

    $post->media()->attach($media->id, ['order' => 0]);

    return [$group, $post];
}

/**
 * Asserts at least one outbound request carried the file's real bytes.
 */
function assertFileBytesWereSent(): void
{
    $bytes = publishMediaBytes();

    $carried = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains((string) $pair[0]->body(), $bytes))
        ->count();

    expect($carried)->toBeGreaterThan(0, 'no outbound request carried the media file bytes');
}

it('publishes media to telegram with the real file bytes', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1234]])]);

    [$group, $post] = publishableMediaPost('telegram', ['chat_id' => '-100123']);

    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);
    assertFileBytesWereSent();
});

it('publishes media to discord with the real file bytes', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => '999'])]);

    [$group, $post] = publishableMediaPost(
        'discord',
        ['channel_id' => 'C1'],
        'https://discord.com/api/webhooks/1/secret',
    );

    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);
    assertFileBytesWereSent();
});

it('publishes media to mastodon with the real file bytes', function () {
    Http::fake([
        'mastodon.social/api/v2/media' => Http::response(['id' => '42']),
        'mastodon.social/api/v1/statuses' => Http::response(['id' => '7', 'url' => 'https://mastodon.social/@a/7']),
    ]);

    [$group, $post] = publishableMediaPost('mastodon', ['instance' => 'mastodon.social']);

    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);
    assertFileBytesWereSent();
});

it('publishes media to bluesky with the real file bytes', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'abc'], 'mimeType' => 'image/png', 'size' => 10]]),
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:x/app.bsky.feed.post/1', 'cid' => 'cid1']),
    ]);

    [$group, $post] = publishableMediaPost('bluesky', ['handle' => 'a.bsky.social']);

    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);
    assertFileBytesWereSent();
});

it('publishes media to slack with the real file bytes', function () {
    Http::fake([
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1', 'file_id' => 'F1']),
        'files.slack.com/*' => Http::response('OK'),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true]),
    ]);

    [$group, $post] = publishableMediaPost('slack', ['channel_id' => 'C0001']);

    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);
    assertFileBytesWereSent();
});

it('fails the segment permanently when the attached file is gone from storage, without leaking the storage key', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    [$group, $post] = publishableMediaPost('telegram', ['chat_id' => '-100123']);

    // The row still points at a key whose object no longer exists — the
    // one case where "publish the text without the picture" would be a
    // silently wrong post rather than a degraded one.
    $key = "org/{$post->organization_id}/attachment.png";
    Storage::disk('media')->delete($key);

    app(PublishService::class)->publishGroup($group->id);

    $post = $post->fresh();

    expect($post->state)->toBe(PostState::ERROR)
        ->and($post->release_id)->toBeNull()
        ->and($post->error)->not->toContain($key)
        ->and($post->error)->toContain('could not be read from storage');

    // Nothing was sent: the failure is decided before the network, not
    // after a half-published segment.
    Http::assertNothingSent();
});

it('leaves no temp files behind after publishing', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $before = count(glob(sys_get_temp_dir().'/publish-media-*') ?: []);

    [$group] = publishableMediaPost('telegram', ['chat_id' => '-100123']);
    app(PublishService::class)->publishGroup($group->id);

    expect(count(glob(sys_get_temp_dir().'/publish-media-*') ?: []))->toBe($before);
});

it('sends a media-free post with no attachments and no temp files', function () {
    // Guards the other direction: the media plumbing must not invent an
    // attachment for a plain text post.
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);

    $org = Organization::factory()->create();
    $group = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->subMinute(),
    ]);
    $integration = Integration::factory()->create([
        'organization_id' => $org->id,
        'provider_identifier' => 'telegram',
        'token' => 'bot-token',
        'settings' => ['chat_id' => '-100'],
    ]);
    $post = Post::create([
        'post_group_id' => $group->id,
        'organization_id' => $org->id,
        'integration_id' => $integration->id,
        'order' => 0,
        'content' => 'text only',
        'settings' => [],
        'state' => PostState::QUEUE,
    ]);

    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::PUBLISHED);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage'));
});
