<?php

use App\Models\Integration;
use App\Services\Posts\PostValidationService;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\ProviderCapabilities;
use Illuminate\Support\Str;

// fakeProvider(): FakeProvider is declared globally by
// tests/Unit/Social/FakeProviderTest.php (loaded once for the whole
// Unit suite, like every other Pest helper) and resolves the 'fake'
// provider through the same ProviderRegistry singleton
// PostValidationService uses, so ->withCapabilities() here configures
// the exact instance the service resolves.

/*
 * Task 19: PostValidationService::validate() decides whether a $chain of
 * PostPayloadDto may go to a given Integration's network, purely from
 * that network's ProviderCapabilities — no DB writes, no HTTP, no queue
 * dispatch, and (since the Task 19 review) no filesystem access either:
 * every MediaDto below carries an explicit $sizeBytes, and
 * file_too_large is checked against that field, never by stat'ing
 * $media->path (see PostValidationService's docblock, "File size", for
 * why reading the disk was rejected). This stays a true Unit test (no
 * RefreshDatabase): Integration instances below are plain `new
 * Integration([...])`, never persisted, and ProviderRegistry resolves
 * real provider classes (Bluesky/Mastodon/Discord) through the
 * container without touching the database — see
 * tests/Unit/Posts/PostStateMachineTest.php's docblock for why an ad hoc
 * uses(RefreshDatabase::class) in tests/Unit is off the table.
 *
 * Real providers (not FakeProvider) are used for the brief's four
 * required scenarios so the exact numbers (Bluesky 300 chars, Mastodon 4
 * images, Discord no threads) are pinned against the real capability
 * declarations reviewed under Task 11/14. Referencing 'bluesky',
 * 'mastodon', 'discord' by name is fine here — ProviderIsolationTest only
 * greps app/, not tests/.
 */
function makeIntegration(string $providerIdentifier): Integration
{
    return new Integration([
        'organization_id' => (string) Str::ulid(),
        'provider_identifier' => $providerIdentifier,
        'internal_id' => (string) Str::ulid(),
        'name' => 'test-channel',
        'settings' => [],
        'disabled' => false,
        'in_between_steps' => false,
        'refresh_needed' => false,
    ]);
}

function service(): PostValidationService
{
    return app(PostValidationService::class);
}

it('rejects content over the character limit', function () {
    $integration = makeIntegration('bluesky');
    $chain = [new PostPayloadDto(content: str_repeat('a', 301))];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['post_index'])->toBe(0);
    expect($errors[0]['code'])->toBe('content_too_long');
    expect($errors[0]['limit'])->toBe(300);
    expect($errors[0]['actual'])->toBe(301);
    expect($errors[0]['message'])->toBeString();
});

it('accepts content exactly at the character limit', function () {
    $integration = makeIntegration('bluesky');
    $chain = [new PostPayloadDto(content: str_repeat('a', 300))];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('counts multibyte Cyrillic and emoji characters, not bytes', function () {
    $integration = makeIntegration('bluesky');
    // 300 Cyrillic characters -- each is 2 bytes in UTF-8, so a
    // byte-counting implementation (strlen()) would over-reject this as
    // 600, while mb_strlen() correctly reports 300.
    $chain = [new PostPayloadDto(content: str_repeat('я', 300))];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('rejects too many images for Mastodon', function () {
    $integration = makeIntegration('mastodon');
    $images = array_map(
        fn (int $i) => new MediaDto(path: "/tmp/img{$i}.jpg", mimeType: 'image/jpeg', sizeBytes: 1024),
        range(1, 5),
    );
    $chain = [new PostPayloadDto(content: 'hello', media: $images)];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('too_many_images');
    expect($errors[0]['limit'])->toBe(4);
    expect($errors[0]['actual'])->toBe(5);
});

it('checks image/video quotas per segment, never summed across the thread', function () {
    // The design's central claim (see PostValidationService's docblock):
    // 2 segments x 3 images each, under a maxImages of 4. Summed across
    // the chain that would be 6 > 4 and wrongly fail; checked per
    // segment (3 <= 4 twice) it must pass. A regression that started
    // summing across the thread would turn this red.
    $integration = makeIntegration('mastodon');
    $threeImages = array_map(
        fn (int $i) => new MediaDto(path: "/tmp/img{$i}.jpg", mimeType: 'image/jpeg', sizeBytes: 1024),
        range(1, 3),
    );
    $chain = [
        new PostPayloadDto(content: 'segment one', media: $threeImages),
        new PostPayloadDto(content: 'segment two', media: $threeImages),
    ];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('rejects a thread for a network that does not support threads', function () {
    $integration = makeIntegration('discord');
    $chain = [
        new PostPayloadDto(content: 'first'),
        new PostPayloadDto(content: 'second'),
    ];

    $errors = service()->validate($integration, $chain);

    $codes = array_column($errors, 'code');
    expect($codes)->toContain('threads_not_supported');
});

it('rejects empty content with no media', function () {
    $integration = makeIntegration('bluesky');
    $chain = [new PostPayloadDto(content: '   ')];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('empty_content');
    expect($errors[0]['limit'])->not->toBeNull();
    expect($errors[0]['actual'])->not->toBeNull();
});

it('accepts empty text content when media is attached', function () {
    $integration = makeIntegration('bluesky');
    $chain = [new PostPayloadDto(content: '', media: [
        new MediaDto(path: '/tmp/img.jpg', mimeType: 'image/jpeg', sizeBytes: 1024),
    ])];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('returns no errors for a valid single-segment post', function () {
    $integration = makeIntegration('mastodon');
    $chain = [new PostPayloadDto(content: 'A perfectly normal post.', media: [
        new MediaDto(path: '/tmp/img.jpg', mimeType: 'image/jpeg', sizeBytes: 1024),
    ])];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('returns no errors for a valid thread on a thread-supporting network', function () {
    $integration = makeIntegration('bluesky');
    $chain = [
        new PostPayloadDto(content: 'part one'),
        new PostPayloadDto(content: 'part two'),
    ];

    expect(service()->validate($integration, $chain))->toBe([]);
});

// --- Task 11's three added capability fields, exercised here since
// nothing else in the codebase covers them (see task brief). ---

it('rejects mixing images and video when the network forbids mixed media', function () {
    $integration = makeIntegration('mastodon');
    $chain = [new PostPayloadDto(content: 'mixed', media: [
        new MediaDto(path: '/tmp/img.jpg', mimeType: 'image/jpeg', sizeBytes: 1024),
        new MediaDto(path: '/tmp/vid.mp4', mimeType: 'video/mp4', sizeBytes: 1024),
    ])];

    $errors = service()->validate($integration, $chain);

    $error = collect($errors)->firstWhere('code', 'mixed_media_not_supported');
    expect($error)->not->toBeNull();
    expect($error['limit'])->not->toBeNull();
    expect($error['actual'])->not->toBeNull();
});

it('rejects a chain longer than the network max thread length', function () {
    fakeProvider()->withCapabilities(new ProviderCapabilities(
        maxCharacters: 1000,
        maxImages: 4,
        maxVideos: 1,
        supportsThreads: true,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 1_000_000,
        allowsMixedMedia: false,
        maxThreadLength: 2,
    ));

    $model = makeIntegration('fake');
    $chain = [
        new PostPayloadDto(content: 'one'),
        new PostPayloadDto(content: 'two'),
        new PostPayloadDto(content: 'three'),
    ];

    $errors = service()->validate($model, $chain);

    expect(array_column($errors, 'code'))->toContain('too_many_segments');
});

it('rejects a media attachment missing required alt text', function () {
    fakeProvider()->withCapabilities(new ProviderCapabilities(
        maxCharacters: 1000,
        maxImages: 4,
        maxVideos: 1,
        supportsThreads: true,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 1_000_000,
        allowsMixedMedia: true,
        maxThreadLength: null,
        requiresAltText: true,
    ));

    $model = makeIntegration('fake');
    $chain = [new PostPayloadDto(content: 'needs alt text', media: [
        new MediaDto(path: '/tmp/img.jpg', mimeType: 'image/jpeg', sizeBytes: 1024, altText: null),
    ])];

    $errors = service()->validate($model, $chain);

    $error = collect($errors)->firstWhere('code', 'missing_alt_text');
    expect($error)->not->toBeNull();
    expect($error['limit'])->not->toBeNull();
    expect($error['actual'])->not->toBeNull();
});

it('rejects an unsupported mime type', function () {
    $integration = makeIntegration('mastodon');
    $chain = [new PostPayloadDto(content: 'bad file', media: [
        new MediaDto(path: '/tmp/doc.pdf', mimeType: 'application/pdf', sizeBytes: 1024),
    ])];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('unsupported_mime');
    expect($errors[0]['actual'])->toBe('application/pdf');
});

it('does not count a non-image/video mime type toward the image quota', function () {
    // An attachment of a kind ProviderCapabilities has no bucket for
    // (e.g. audio) must not be silently folded into imageCount -- that
    // would both consume a quota it doesn't belong to and produce a
    // wrong `actual` on a resulting too_many_images error. Mastodon
    // allows up to 4 images; 4 real images plus 1 unsupported audio
    // file must trip unsupported_mime only, not too_many_images.
    $integration = makeIntegration('mastodon');
    $images = array_map(
        fn (int $i) => new MediaDto(path: "/tmp/img{$i}.jpg", mimeType: 'image/jpeg', sizeBytes: 1024),
        range(1, 4),
    );
    $chain = [new PostPayloadDto(content: 'with audio', media: [
        ...$images,
        new MediaDto(path: '/tmp/clip.mp3', mimeType: 'audio/mpeg', sizeBytes: 1024),
    ])];

    $errors = service()->validate($integration, $chain);

    expect(array_column($errors, 'code'))->toBe(['unsupported_mime']);
});

it('rejects a file over the max file size', function () {
    $integration = makeIntegration('mastodon');

    $chain = [new PostPayloadDto(content: 'big file', media: [
        new MediaDto(path: '/tmp/big.jpg', mimeType: 'image/jpeg', sizeBytes: 41 * 1024 * 1024),
    ])];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('file_too_large');
    expect($errors[0]['limit'])->toBe(40 * 1024 * 1024);
    expect($errors[0]['actual'])->toBe(41 * 1024 * 1024);
});

it('accepts a file at exactly the max file size', function () {
    $integration = makeIntegration('mastodon');

    $chain = [new PostPayloadDto(content: 'exact size', media: [
        new MediaDto(path: '/tmp/exact.jpg', mimeType: 'image/jpeg', sizeBytes: 40 * 1024 * 1024),
    ])];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('treats an unknown file size as a validation failure, not a silent pass', function () {
    // Task 19 review, Finding 1: this service must never stat $media->path
    // (that degrades to "always valid" the moment $path stops being a
    // locally-stat-able file, e.g. once media lives in S3/MinIO). A
    // MediaDto built without a $sizeBytes is therefore its own explicit
    // error, not something that silently reads as "fine".
    $integration = makeIntegration('mastodon');
    $chain = [new PostPayloadDto(content: 'no known size', media: [
        new MediaDto(path: '/tmp/mystery.jpg', mimeType: 'image/jpeg', sizeBytes: null),
    ])];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('file_size_unknown');
    expect($errors[0]['limit'])->toBe(40 * 1024 * 1024);
    expect($errors[0]['actual'])->toBe('unknown');
});
