<?php

use App\Social\Dto\PostPayloadDto;

it('starts with a null replyToReleaseId on a normal payload', function () {
    $payload = new PostPayloadDto('hello');

    expect($payload->replyToReleaseId)->toBeNull();
});

it('returns a new, immutable instance from withReplyToReleaseId, leaving the original untouched', function () {
    $original = new PostPayloadDto('hello', settings: ['contentWarning' => 'spoilers']);

    $anchored = $original->withReplyToReleaseId('release-123');

    expect($original->replyToReleaseId)->toBeNull()
        ->and($anchored)->not->toBe($original)
        ->and($anchored->replyToReleaseId)->toBe('release-123')
        ->and($anchored->content)->toBe($original->content)
        ->and($anchored->media)->toBe($original->media)
        ->and($anchored->settings)->toBe($original->settings);
});
