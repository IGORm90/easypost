<?php

use App\Models\Media;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/*
 * Block 5.0.4: Instagram and Threads accept no upload — a post is created
 * by handing the Graph API a URL the network fetches itself. Every other
 * provider in this app reads bytes from a local temp copy. `Media::toDto()`
 * is the single point where a stored file becomes something a provider can
 * see, so it is where that URL has to be attached; a provider building the
 * link itself would need to know about disks and `AWS_URL`, which is
 * exactly the knowledge app/Social is not allowed to hold.
 */
it('hands the publishing pipeline a url the network can fetch the file from', function () {
    Storage::fake('media');
    $org = Organization::factory()->create();

    $media = Media::factory()->create([
        'organization_id' => $org->id,
        'disk' => 'media',
        'path' => 'org/'.$org->id.'/photo.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $dto = $media->toDto();

    // Same URL the SPA is already given for this file (MediaResource::$url) —
    // deliberately not a second, differently-built link that could drift.
    expect($dto->publicUrl)->toBe($media->url());
    expect($dto->publicUrl)->toContain('photo.jpg');
    // The storage key still travels separately: every byte-based provider
    // reads $path, and PublishService swaps it for a local copy.
    expect($dto->path)->toBe('org/'.$org->id.'/photo.jpg');
});
