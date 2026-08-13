<?php

use App\Social\Dto\MediaDto;

it('starts with a null remoteId until an upload attaches one', function () {
    $media = new MediaDto(path: '/tmp/photo.jpg', mimeType: 'image/jpeg');

    expect($media->remoteId)->toBeNull();
});

it('returns a new, immutable instance from withRemoteId, leaving the original untouched', function () {
    $original = new MediaDto(path: '/tmp/photo.jpg', mimeType: 'image/jpeg', altText: 'a cat');

    $uploaded = $original->withRemoteId('remote-123');

    expect($original->remoteId)->toBeNull()
        ->and($uploaded)->not->toBe($original)
        ->and($uploaded->remoteId)->toBe('remote-123')
        ->and($uploaded->path)->toBe($original->path)
        ->and($uploaded->mimeType)->toBe($original->mimeType)
        ->and($uploaded->altText)->toBe($original->altText);
});
