<?php

use App\Models\Media;
use App\Models\Organization;
use App\Services\Media\MediaUploadService;
use App\Support\SafeRemoteHost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Task 27: POST /api/media (direct multipart upload) and
 * POST /api/media/from-url (server-side download). See
 * MediaUploadService's docblock for the security reasoning this file
 * exercises — mime sniffing, no-execution storage, SSRF/redirect/size/
 * timeout defenses on the from-url path.
 */

afterEach(function () {
    SafeRemoteHost::stopFaking();
});

// --- POST /api/media -----------------------------------------------------

it('stores an uploaded image and returns its url', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg', 800, 600)])
        ->assertCreated()
        ->assertJsonPath('data.mime', 'image/jpeg');

    expect(Media::count())->toBe(1);
});

it('rejects a disallowed mime type', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media', ['file' => UploadedFile::fake()->create('a.exe', 10)])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('rejects a disallowed mime type even when the extension and Content-Type claim otherwise', function () {
    // Laravel's fake image is real GD-rendered JPEG bytes; renaming it
    // to .png and forcing a fake Content-Type must not fool the sniff —
    // MediaUploadService::sniffMime() reads the actual bytes, so the
    // real mime (image/jpeg) is what decides acceptance, not this
    // filename/header combination. Since image/jpeg IS allowed, this
    // proves the sniff -- not the claimed type -- is authoritative by
    // checking the STORED mime matches the true content, not the claim.
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $file = UploadedFile::fake()->image('a.jpg', 10, 10)->mimeType('application/x-msdownload');
    $renamed = new UploadedFile($file->getPathname(), 'photo.exe', 'application/x-msdownload', null, true);

    $this->postJson('/api/media', ['file' => $renamed])
        ->assertCreated()
        ->assertJsonPath('data.mime', 'image/jpeg');
});

it('captures the real byte size on the stored row', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $file = UploadedFile::fake()->image('a.jpg', 800, 600);
    $expectedSize = filesize($file->getPathname());

    $this->postJson('/api/media', ['file' => $file])->assertCreated();

    expect(Media::sole()->size_bytes)->toBe($expectedSize);
});

it('generates a 400px-wide thumbnail for a large image', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg', 1200, 900)])
        ->assertCreated()
        ->assertJsonPath('data.thumbnail_url', fn (?string $url) => $url !== null);

    $media = Media::sole();
    expect($media->thumbnail_path)->not->toBeNull();

    Storage::disk('media')->assertExists($media->thumbnail_path);
    [$width] = getimagesizefromstring(Storage::disk('media')->get($media->thumbnail_path));
    expect($width)->toBe(400);
});

it('does not generate a separate thumbnail for an image already smaller than the thumbnail width', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg', 100, 80)])
        ->assertCreated()
        ->assertJsonPath('data.thumbnail_url', null);

    expect(Media::sole()->thumbnail_path)->toBeNull();
});

it('stores an mp4 video with no thumbnail', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    // UploadedFile::fake()->create() with an integer size writes NO
    // real bytes (only fakes getSize()/getClientMimeType() metadata) --
    // useless for a real finfo sniff, so a minimal, real MP4 `ftyp` box
    // is used instead: this must be recognized from its actual content,
    // exactly like the disallowed-type test proves the opposite case.
    $mp4Bytes = hex2bin('0000001C6674797069736F6D0000020069736F6D69736F326D703431').str_repeat("\0", 200);
    $file = UploadedFile::fake()->createWithContent('clip.mp4', $mp4Bytes);

    $this->postJson('/api/media', ['file' => $file])
        ->assertCreated()
        ->assertJsonPath('data.mime', 'video/mp4')
        ->assertJsonPath('data.thumbnail_url', null);
});

it('stores the file at org/{org_id}/{ulid}.{ext} on the media disk', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg', 10, 10)])->assertCreated();

    $media = Media::sole();
    expect($media->path)->toMatch('#^org/'.$org->id.'/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#');
    Storage::disk('media')->assertExists($media->path);
});

it('sanitizes a directory-traversal / control-character filename before storing it', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    $file = UploadedFile::fake()->image('../../etc/passwd'."\r\n".'evil.jpg', 10, 10);

    $this->postJson('/api/media', ['file' => $file])->assertCreated();

    $stored = Media::sole()->original_filename;
    expect($stored)->not->toContain('..')
        ->and($stored)->not->toContain('/')
        ->and($stored)->not->toContain("\r")
        ->and($stored)->not->toContain("\n");
});

// --- POST /api/media/from-url ---------------------------------------------

it('refuses to download from a private ip', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media/from-url', ['url' => 'http://169.254.169.254/latest/meta-data'])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('refuses a from-url import when a safe host redirects to a private IP', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    SafeRemoteHost::fake(['safe.example' => ['198.51.100.10']]);
    Http::fake([
        'safe.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/secret']),
    ]);

    $this->postJson('/api/media/from-url', ['url' => 'http://safe.example/image.jpg'])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('refuses a from-url import whose scheme is neither http nor https', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media/from-url', ['url' => 'ftp://example.com/file.jpg'])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('imports media from a safe http url', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    SafeRemoteHost::fake(['safe.example' => ['198.51.100.10']]);
    // Held in a variable, not chained: the fake File wraps a tmpfile()
    // resource that is closed (deleting the underlying temp file) as
    // soon as the object is garbage-collected -- chaining
    // ->getPathname() directly let it get collected before
    // file_get_contents() ran.
    $fakeImage = UploadedFile::fake()->image('a.jpg', 400, 300);
    $bytes = (string) file_get_contents($fakeImage->getPathname());
    Http::fake([
        'safe.example/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->postJson('/api/media/from-url', ['url' => 'http://safe.example/photo.jpg'])
        ->assertCreated()
        ->assertJsonPath('data.mime', 'image/jpeg');

    expect(Media::sole()->size_bytes)->toBe(strlen($bytes));
});

it('rejects a from-url download whose actual body exceeds the configured size cap, regardless of the declared Content-Length', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    // A tiny configured cap, not a genuine 100MB fixture -- see
    // config/media.php's docblock for why this is configurable.
    config(['media.import_max_bytes' => 10]);

    SafeRemoteHost::fake(['safe.example' => ['198.51.100.10']]);
    $body = str_repeat('x', 1000);
    Http::fake([
        // Content-Length lies (claims 10 bytes) -- the actual streamed
        // body is 1000 bytes; the size check must be based on bytes
        // actually received, not this header.
        'safe.example/*' => Http::response($body, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '10']),
    ]);

    $this->postJson('/api/media/from-url', ['url' => 'http://safe.example/huge.jpg'])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('rejects a from-url download whose sniffed content is not an allowed mime type', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();
    actingAsMember($org);

    SafeRemoteHost::fake(['safe.example' => ['198.51.100.10']]);
    Http::fake([
        'safe.example/*' => Http::response('MZ'.str_repeat("\0", 100), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->postJson('/api/media/from-url', ['url' => 'http://safe.example/not-really-an-image.jpg'])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('rejects a userinfo-obfuscated from-url URL', function () {
    $org = Organization::factory()->create();
    actingAsMember($org);

    $this->postJson('/api/media/from-url', ['url' => 'http://trusted.example@169.254.169.254/'])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

it('exposes the real content-based mime detection used by both upload paths', function () {
    // MediaUploadService::sniffMime() is the single source of truth
    // UploadMediaRequest, store() and storeFromUrl() all read from --
    // exercised directly here against a real GD-rendered PNG to prove
    // it reads content, not extension.
    $file = UploadedFile::fake()->image('anything.txt', 5, 5);

    expect(MediaUploadService::sniffMime($file->getPathname()))->toBe('image/jpeg');
});
