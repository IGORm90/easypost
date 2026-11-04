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
 * real provider classes (Telegram/Bluesky) through the
 * container without touching the database — see
 * tests/Unit/Posts/PostGroupLifecycleTest.php's docblock for why an ad hoc
 * uses(RefreshDatabase::class) in tests/Unit is off the table.
 *
 * Real providers (not FakeProvider) are used wherever the point is to
 * pin an exact number against a shipped capability declaration (Bluesky:
 * 300 characters, 4 images, no mixed media, a 1 MB file cap). Capability
 * combinations no shipped network has — threads unsupported, alt text
 * required — go through FakeProvider instead. Referencing 'telegram' and
 * 'bluesky' by name is fine here — ProviderIsolationTest only greps
 * app/, not tests/.
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

it('rejects too many images for Bluesky', function () {
    $integration = makeIntegration('bluesky');
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
    $integration = makeIntegration('bluesky');
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
    // No shipped network refuses threads any more (both Telegram and
    // Bluesky chain), so the capability is exercised through the fake
    // rather than by picking a provider that happens to say false.
    fakeProvider()->withCapabilities(new ProviderCapabilities(
        maxCharacters: 1000,
        maxImages: 4,
        maxVideos: 1,
        supportsThreads: false,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 1_000_000,
        allowsMixedMedia: true,
        maxThreadLength: null,
        supportedMarks: [],
        requiresMedia: false,
    ));

    $integration = makeIntegration('fake');
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
    $integration = makeIntegration('bluesky');
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
    // Through the fake, not Bluesky: Bluesky forbids mixed media AND
    // rejects video outright, so a real mixed attachment there would
    // trip three codes at once and stop proving this one.
    fakeProvider()->withCapabilities(new ProviderCapabilities(
        maxCharacters: 1000,
        maxImages: 4,
        maxVideos: 1,
        supportsThreads: true,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg', 'video/mp4'],
        maxFileSizeBytes: 1_000_000,
        allowsMixedMedia: false,
        maxThreadLength: null,
        supportedMarks: [],
        requiresMedia: false,
    ));

    $integration = makeIntegration('fake');
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
        supportedMarks: [],
        requiresMedia: false,
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
        supportedMarks: [],
        requiresMedia: false,
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
    $integration = makeIntegration('bluesky');
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
    // wrong `actual` on a resulting too_many_images error. Bluesky
    // allows up to 4 images; 4 real images plus 1 unsupported audio
    // file must trip unsupported_mime only, not too_many_images.
    $integration = makeIntegration('bluesky');
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
    $integration = makeIntegration('bluesky');

    $chain = [new PostPayloadDto(content: 'big file', media: [
        new MediaDto(path: '/tmp/big.jpg', mimeType: 'image/jpeg', sizeBytes: 1_000_001),
    ])];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('file_too_large');
    expect($errors[0]['limit'])->toBe(1_000_000);
    expect($errors[0]['actual'])->toBe(1_000_001);
});

it('accepts a file at exactly the max file size', function () {
    $integration = makeIntegration('bluesky');

    $chain = [new PostPayloadDto(content: 'exact size', media: [
        new MediaDto(path: '/tmp/exact.jpg', mimeType: 'image/jpeg', sizeBytes: 1_000_000),
    ])];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('treats an unknown file size as a validation failure, not a silent pass', function () {
    // Task 19 review, Finding 1: this service must never stat $media->path
    // (that degrades to "always valid" the moment $path stops being a
    // locally-stat-able file, e.g. once media lives in S3/MinIO). A
    // MediaDto built without a $sizeBytes is therefore its own explicit
    // error, not something that silently reads as "fine".
    $integration = makeIntegration('bluesky');
    $chain = [new PostPayloadDto(content: 'no known size', media: [
        new MediaDto(path: '/tmp/mystery.jpg', mimeType: 'image/jpeg', sizeBytes: null),
    ])];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('file_size_unknown');
    expect($errors[0]['limit'])->toBe(1_000_000);
    expect($errors[0]['actual'])->toBe('unknown');
});

/*
 * Block 5.0.5: `requiresMedia`. Instagram cannot publish a post without a
 * picture at all. Before this capability existed, such a post passed
 * scheduling, sat in the queue, and only then collected a rejection from
 * the network and an ERROR state — the failure surfaced hours after the
 * customer could still have fixed it. The rule belongs where every other
 * "may this content go to this channel" rule already lives.
 */
it('rejects a text-only segment on a network that requires media', function () {
    fakeProvider()->withCapabilities(new ProviderCapabilities(
        maxCharacters: 2200,
        maxImages: 10,
        maxVideos: 1,
        supportsThreads: false,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 8 * 1024 * 1024,
        allowsMixedMedia: false,
        maxThreadLength: null,
        supportedMarks: [],
        requiresMedia: true,
    ));

    $model = makeIntegration('fake');
    $chain = [new PostPayloadDto(content: 'a caption with nothing to caption')];

    $errors = service()->validate($model, $chain);

    $error = collect($errors)->firstWhere('code', 'media_required');
    expect($error)->not->toBeNull();
    expect($error['post_index'])->toBe(0);
    expect($error['limit'])->toBe(1);
    expect($error['actual'])->toBe(0);
    expect($error['message'])->toBeString();
});

it('accepts a segment carrying media on a network that requires it', function () {
    fakeProvider()->withCapabilities(new ProviderCapabilities(
        maxCharacters: 2200,
        maxImages: 10,
        maxVideos: 1,
        supportsThreads: false,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 8 * 1024 * 1024,
        allowsMixedMedia: false,
        maxThreadLength: null,
        supportedMarks: [],
        requiresMedia: true,
    ));

    $model = makeIntegration('fake');
    $chain = [new PostPayloadDto(content: 'a real caption', media: [
        new MediaDto(path: '/tmp/img.jpg', mimeType: 'image/jpeg', sizeBytes: 2048),
    ])];

    expect(service()->validate($model, $chain))->toBe([]);
});

it('reports media_required on every segment of a thread that lacks media', function () {
    // The check is per segment, like every other segment-level rule. A
    // network could support threads and still require media on each post;
    // scoping the rule to "the first segment" would be a guess about the
    // networks we happen to have.
    fakeProvider()->withCapabilities(new ProviderCapabilities(
        maxCharacters: 500,
        maxImages: 4,
        maxVideos: 1,
        supportsThreads: true,
        supportsScheduling: false,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 8 * 1024 * 1024,
        allowsMixedMedia: false,
        maxThreadLength: null,
        supportedMarks: [],
        requiresMedia: true,
    ));

    $model = makeIntegration('fake');
    $chain = [
        new PostPayloadDto(content: 'one', media: [
            new MediaDto(path: '/tmp/img.jpg', mimeType: 'image/jpeg', sizeBytes: 2048),
        ]),
        new PostPayloadDto(content: 'two'),
    ];

    $errors = service()->validate($model, $chain);

    expect(array_column($errors, 'code'))->toBe(['media_required']);
    expect($errors[0]['post_index'])->toBe(1);
});

it('does not report media_required on a network that does not require media', function () {
    $integration = makeIntegration('bluesky');

    expect(service()->validate($integration, [new PostPayloadDto(content: 'just text')]))->toBe([]);
});

/*
 * Block 5.0.6: per-post settings. A provider declares which keys a post
 * to its network must carry (Reddit: a subreddit and a title); this
 * service is where that declaration becomes a verdict, for the same
 * reason every other capability rule lives here — scheduling and
 * publishing must reach the same answer, and only this class is called by
 * both.
 */
it('rejects a post missing a setting the network requires', function () {
    $model = makeIntegration('post-settings-fake');
    $chain = [new PostPayloadDto(content: 'body', settings: ['title' => 'hi'])];

    $errors = service()->validate($model, $chain);

    $error = collect($errors)->firstWhere('code', 'post_setting_required');
    expect($error)->not->toBeNull();
    expect($error['post_index'])->toBe(0);
    // The key names the field, so the editor can put the message on it
    // instead of at the top of the form.
    expect($error['limit'])->toBe('topic');
});

it('treats a blank string as a missing required setting', function () {
    // An empty text input posts '', not absence. Accepting it would send
    // Reddit a submission with an empty title, which it rejects — later,
    // and with its own wording.
    $model = makeIntegration('post-settings-fake');
    $chain = [new PostPayloadDto(content: 'body', settings: ['topic' => 'jazz', 'title' => '   '])];

    $errors = service()->validate($model, $chain);

    expect(collect($errors)->firstWhere('limit', 'title')['code'])->toBe('post_setting_required');
});

it('rejects a setting value that fails the provider rules', function () {
    $model = makeIntegration('post-settings-fake');
    $chain = [new PostPayloadDto(content: 'body', settings: [
        'topic' => 'jazz',
        'title' => 'far too long for the declared limit',
    ])];

    $errors = service()->validate($model, $chain);

    $error = collect($errors)->firstWhere('code', 'post_setting_invalid');
    expect($error)->not->toBeNull();
    expect($error['limit'])->toBe('title');
});

it('accepts a post carrying every required setting', function () {
    $model = makeIntegration('post-settings-fake');
    $chain = [new PostPayloadDto(content: 'body', settings: ['topic' => 'jazz', 'title' => 'ok'])];

    expect(service()->validate($model, $chain))->toBe([]);
});

it('ignores settings entirely for a provider that declares none', function () {
    // Settings have been a free-form pass-through since Task 20, and
    // several posts in the wild carry keys nothing reads. Declaring the
    // contract must not retroactively make those posts invalid.
    $integration = makeIntegration('bluesky');
    $chain = [new PostPayloadDto(content: 'body', settings: ['anything' => 'at all'])];

    expect(service()->validate($integration, $chain))->toBe([]);
});

it('checks required settings on every segment of a thread', function () {
    $model = makeIntegration('post-settings-fake');
    $chain = [
        new PostPayloadDto(content: 'one', settings: ['topic' => 'jazz', 'title' => 'ok']),
        new PostPayloadDto(content: 'two', settings: ['topic' => 'jazz', 'title' => 'ok']),
    ];

    $errors = service()->validate($model, $chain);

    // This provider does not support threads, so the chain is rejected
    // for that reason — and NOT additionally for settings, which are
    // present on both segments.
    expect(array_column($errors, 'code'))->toBe(['threads_not_supported']);
});

/*
 * Soft degradation for a channel whose network this install no longer
 * ships. `ProviderRegistry::get()` throws UnknownProviderException (an
 * InvalidArgumentException, deliberately NOT a ProviderException), so
 * before this branch existed a single stored Integration pointing at a
 * removed provider turned every scheduling request that touched it into
 * a 500. The customer cannot fix a 500; they can read "this network is
 * no longer available" and delete the channel.
 */
it('reports the network as unavailable instead of throwing when the provider is no longer registered', function () {
    $integration = makeIntegration('network-removed-from-this-install');
    $chain = [new PostPayloadDto(content: 'hello')];

    $errors = service()->validate($integration, $chain);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['code'])->toBe('provider_unavailable')
        ->and($errors[0]['post_index'])->toBe(0)
        ->and($errors[0]['message'])->toBeString();
});
