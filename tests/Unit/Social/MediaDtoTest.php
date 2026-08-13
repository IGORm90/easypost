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
