<?php

use App\Social\Dto\MediaDto;

it('starts with a null remoteId until an upload attaches one', function () {
    $media = new MediaDto(path: '/tmp/photo.jpg', mimeType: 'image/jpeg');

    expect($media->remoteId)->toBeNull();
});

it('defaults sizeBytes to null when not specified', function () {
    $media = new MediaDto(path: '/tmp/photo.jpg', mimeType: 'image/jpeg');

    expect($media->sizeBytes)->toBeNull();
});

it('returns a new, immutable instance from withRemoteId, leaving the original untouched', function () {
    $original = new MediaDto(path: '/tmp/photo.jpg', mimeType: 'image/jpeg', sizeBytes: 12_345, altText: 'a cat');

    $uploaded = $original->withRemoteId('remote-123');

    expect($original->remoteId)->toBeNull()
        ->and($uploaded)->not->toBe($original)
        ->and($uploaded->remoteId)->toBe('remote-123')
        ->and($uploaded->path)->toBe($original->path)
        ->and($uploaded->mimeType)->toBe($original->mimeType)
        ->and($uploaded->sizeBytes)->toBe($original->sizeBytes)
        ->and($uploaded->altText)->toBe($original->altText);
});

/*
 * Re-review round 4, finding A: filename()'s 255-character cap was the
 * one line of its commit no test covered — deleting it left the suite
 * green. The cap exists so the two places that produce a wire-bound
 * filename (this accessor and MediaUploadService::sanitizeFilename())
 * cannot disagree about limits.
 */
it('caps the filename it hands a provider at 255 characters', function () {
    $media = new MediaDto(
        path: '/tmp/publish-media-abc.png',
        mimeType: 'image/png',
        originalFilename: str_repeat('a', 300).'.png',
    );

    expect(mb_strlen($media->filename()))->toBe(255)
        ->and($media->filename())->toBe(str_repeat('a', 255));
});

it('counts the cap in characters, not bytes, so a multibyte name is not cut mid-character', function () {
    $media = new MediaDto(
        path: '/tmp/publish-media-abc.png',
        mimeType: 'image/png',
        originalFilename: str_repeat('é', 300).'.png',
    );

    $filename = $media->filename();

    expect(mb_strlen($filename))->toBe(255)
        ->and(mb_check_encoding($filename, 'UTF-8'))->toBeTrue();
});

it('falls back to the path basename when no original filename is known', function () {
    $media = new MediaDto(path: '/tmp/publish-media-abc.png', mimeType: 'image/png');

    expect($media->filename())->toBe('publish-media-abc.png');
});

/*
 * Block 5.0.4: Meta's networks (Instagram, Threads) do not accept an
 * uploaded file at all — a post is created by handing the Graph API a URL
 * the network fetches for itself. Every other provider in this app is
 * handed BYTES, from a local temp copy, and never a link. So the DTO has
 * to carry both: $path (what the eight byte-based providers read) and
 * $publicUrl (what the URL-based ones read), with the second surviving
 * the withPath() swap the publishing pipeline performs.
 */
it('carries a public url through the local-copy path swap', function () {
    $dto = new MediaDto(
        path: 'org/01ABC/photo.jpg',
        mimeType: 'image/jpeg',
        sizeBytes: 2048,
        publicUrl: 'https://cdn.example.test/media/org/01ABC/photo.jpg',
    );

    // PublishService swaps the storage key for a readable local file
    // immediately before handing the DTO to a provider. If publicUrl were
    // dropped there, Instagram would be handed a container request with
    // no image_url and fail at publish time only.
    $swapped = $dto->withPath('/tmp/publish-media-XYZ.jpg');

    expect($swapped->path)->toBe('/tmp/publish-media-XYZ.jpg');
    expect($swapped->publicUrl)->toBe('https://cdn.example.test/media/org/01ABC/photo.jpg');
});

it('carries a public url through the upload-handle swap', function () {
    $dto = new MediaDto(
        path: '/tmp/photo.jpg',
        mimeType: 'image/jpeg',
        sizeBytes: 2048,
        publicUrl: 'https://cdn.example.test/media/photo.jpg',
    );

    expect($dto->withRemoteId('media-99')->publicUrl)->toBe('https://cdn.example.test/media/photo.jpg');
});

it('defaults the public url to null for a hand-built dto', function () {
    // Providers that read bytes must be able to build a DTO without
    // knowing anything about object storage — their own unit tests do
    // exactly that.
    expect((new MediaDto(path: '/tmp/a.jpg', mimeType: 'image/jpeg'))->publicUrl)->toBeNull();
});
