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
function publishableMediaPost(string $provider, array $settings = [], string $token = 'provider-token', string $originalFilename = 'attachment.png'): array
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
        'original_filename' => $originalFilename,
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

it('sends the customer\'s own filename, not the temp copy\'s name', function () {
    // Re-review finding 6: providers derived the multipart filename from
    // basename($media->path), which — once the pipeline started handing
    // them a temp copy — meant every attachment reached the network named
    // `publish-media-XXXXXX.png`. Slack shows that name to users.
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    [$group] = publishableMediaPost('telegram', ['chat_id' => '-100123']);

    app(PublishService::class)->publishGroup($group->id);

    $bodies = collect(Http::recorded())->map(fn (array $pair): string => (string) $pair[0]->body());

    expect($bodies->filter(fn (string $body): bool => str_contains($body, 'filename="attachment.png"'))->count())
        ->toBeGreaterThan(0, 'the original filename never reached the wire')
        ->and($bodies->filter(fn (string $body): bool => str_contains($body, 'publish-media-'))->count())
        ->toBe(0, 'the temp copy\'s name leaked to the network');
});

it('sends the customer\'s own filename on every provider that attaches files', function (string $provider, array $settings, string $token) {
    // Re-review round 2: only Telegram was asserted, so the same mutation
    // in the other three broke nothing.
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'discord.com/*' => Http::response(['id' => '1']),
        'mastodon.social/api/v2/media' => Http::response(['id' => '42']),
        'mastodon.social/api/v1/statuses' => Http::response(['id' => '7']),
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1', 'file_id' => 'F1']),
        'files.slack.com/*' => Http::response('OK'),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.1']),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true]),
    ]);

    [$group] = publishableMediaPost($provider, $settings, $token);

    app(PublishService::class)->publishGroup($group->id);

    $bodies = collect(Http::recorded())->map(fn (array $pair): string => (string) $pair[0]->body());

    expect($bodies->filter(fn (string $b): bool => str_contains($b, 'filename="attachment.png"'))->count())
        ->toBeGreaterThan(0, "[{$provider}] never sent the original filename")
        ->and($bodies->filter(fn (string $b): bool => str_contains($b, 'publish-media-'))->count())
        ->toBe(0, "[{$provider}] leaked the temp copy's name");
})->with([
    ['telegram', ['chat_id' => '-100'], 'bot-token'],
    ['discord', [], 'https://discord.com/api/webhooks/1/secret'],
    ['mastodon', ['instance' => 'mastodon.social'], 'access-token'],
    ['slack', ['channel_id' => 'C1'], 'xoxb-token'],
]);

it('cannot be made to inject extra parameters into the multipart header via the filename', function () {
    // Re-review round 2, finding 1, demonstrated on the wire by the
    // reviewer: Guzzle builds `form-data; name="%s"; filename="%s"` with
    // sprintf and no escaping, so a quote in a customer-supplied filename
    // appends attacker-chosen parameters to that header.
    Http::fake(['discord.com/*' => Http::response(['id' => '1'])]);

    [$group] = publishableMediaPost(
        'discord',
        [],
        'https://discord.com/api/webhooks/1/secret',
        'a.png"; name="payload_json"; x="',
    );

    app(PublishService::class)->publishGroup($group->id);

    $body = (string) collect(Http::recorded())->first()[0]->body();

    // Exactly one `name=` parameter per part, and the file part is still
    // the one Discord expects.
    expect(substr_count($body, 'name="payload_json"'))->toBe(1)
        ->and($body)->toContain('name="files[0]"; filename="a.png; name=payload_json; x="')
        ->and(substr_count($body, 'name="files[0]"'))->toBe(1);
});

it('sends the part content type from the stored mime, not from the customer\'s filename', function () {
    // Re-review round 2, finding 2: Guzzle infers a part's Content-Type
    // from the FILENAME when the caller supplies none, so a genuine PNG
    // uploaded as `photo.txt` used to go out as text/plain.
    Http::fake(['discord.com/*' => Http::response(['id' => '1'])]);

    [$group] = publishableMediaPost('discord', [], 'https://discord.com/api/webhooks/1/secret', 'photo.txt');

    app(PublishService::class)->publishGroup($group->id);

    $body = (string) collect(Http::recorded())->first()[0]->body();

    expect($body)->toContain('Content-Type: image/png')
        ->and($body)->not->toContain('Content-Type: text/plain');
});

it('leaves no temp files behind after publishing', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $before = count(glob(sys_get_temp_dir().'/publish-media-*') ?: []);

    [$group] = publishableMediaPost('telegram', ['chat_id' => '-100123']);
    app(PublishService::class)->publishGroup($group->id);

    expect(count(glob(sys_get_temp_dir().'/publish-media-*') ?: []))->toBe($before);
});

it('leaves no temp file behind when the copy itself fails', function () {
    // Re-review finding 1, proven live by the reviewer: tempnam() creates
    // the file before a single byte is read, and every failure path used
    // to walk past it — so each retry of a publish with an unreadable
    // attachment leaked another temp file, and the caller could not clean
    // up a path it was never given.
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    [$group, $post] = publishableMediaPost('telegram', ['chat_id' => '-100123']);
    Storage::disk('media')->delete("org/{$post->organization_id}/attachment.png");

    $before = count(glob(sys_get_temp_dir().'/publish-media-*') ?: []);

    // Twice: a leak that only shows up on retry is the shape that
    // actually fills a worker's disk in production.
    app(PublishService::class)->publishGroup($group->id);
    $post->fresh()->update(['state' => PostState::QUEUE, 'error' => null]);
    app(PublishService::class)->publishGroup($group->id);

    expect(count(glob(sys_get_temp_dir().'/publish-media-*') ?: []))->toBe($before);
});

it('leaves no temp file behind when the stored object is empty', function () {
    // The other half of the same finding: a zero-byte object is refused
    // AFTER the copy has already created (and written to) a temp file.
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    [$group, $post] = publishableMediaPost('telegram', ['chat_id' => '-100123']);
    Storage::disk('media')->put("org/{$post->organization_id}/attachment.png", '');

    $before = count(glob(sys_get_temp_dir().'/publish-media-*') ?: []);

    app(PublishService::class)->publishGroup($group->id);

    expect($post->fresh()->state)->toBe(PostState::ERROR)
        ->and(count(glob(sys_get_temp_dir().'/publish-media-*') ?: []))->toBe($before);
});

it('does not pre-upload anything for a superseded schedule generation', function () {
    // The cheap pre-check: a run whose generation has been superseded
    // must not pay for uploads before discovering it has nothing to do.
    Http::fake(['mastodon.social/*' => Http::response(['id' => '1'])]);

    [$group] = publishableMediaPost('mastodon', ['instance' => 'mastodon.social']);
    $group->update(['queue_version' => 7]);

    app(PublishService::class)->publishGroup($group->id, 6);

    Http::assertNothingSent();
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
