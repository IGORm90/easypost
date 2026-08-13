<?php

use App\Models\Integration;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\ProviderRegistry;
use Tests\Support\FakeProvider;

function fakeProvider(): FakeProvider
{
    /** @var FakeProvider $provider */
    $provider = app(ProviderRegistry::class)->get('fake');

    return $provider->reset();
}

it('publishes a chain and records it', function () {
    $provider = fakeProvider();
    $integration = new Integration;
    $chain = [new PostPayloadDto('hello world')];

    $result = $provider->post($integration, $chain);

    expect($result->releaseId)->toStartWith('fake-release-')
        ->and($result->releaseUrl)->toContain($result->releaseId)
        ->and($provider->publishedChains())->toHaveCount(1)
        ->and($provider->publishedChains()[0])->toBe($chain);
});

it('throws a transient exception once, then succeeds', function () {
    $provider = fakeProvider()->failNextPostTransiently('boom');
    $integration = new Integration;
    $chain = [new PostPayloadDto('retry me')];

    expect(fn () => $provider->post($integration, $chain))
        ->toThrow(ProviderTransientException::class, 'boom');

    // queued failure is consumed — the retry succeeds
    $result = $provider->post($integration, $chain);
    expect($result->releaseId)->toStartWith('fake-release-');
});

it('throws a permanent exception on demand', function () {
    $provider = fakeProvider()->failNextPostPermanently('rejected');
    $integration = new Integration;

    expect(fn () => $provider->post($integration, [new PostPayloadDto('x')]))
        ->toThrow(ProviderPermanentException::class, 'rejected');
});

it('throws an auth exception on demand', function () {
    $provider = fakeProvider()->failNextPostWithAuthError('token dead');
    $integration = new Integration;

    expect(fn () => $provider->post($integration, [new PostPayloadDto('x')]))
        ->toThrow(ProviderAuthException::class, 'token dead');
});

it('throws a rate limit exception carrying retryAfterSeconds', function () {
    $provider = fakeProvider()->failNextPostWithRateLimit(42, 'slow down');
    $integration = new Integration;

    try {
        $provider->post($integration, [new PostPayloadDto('x')]);
        $this->fail('expected ProviderRateLimitException');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(42)
            ->and($e->getMessage())->toBe('slow down');
    }
});

it('lets tests override capabilities', function () {
    $provider = fakeProvider();
    $custom = new ProviderCapabilities(
        maxCharacters: 280,
        maxImages: 0,
        maxVideos: 0,
        supportsThreads: false,
        supportsScheduling: false,
        allowedMimeTypes: [],
        maxFileSizeBytes: 0,
    );

    $provider->withCapabilities($custom);

    expect($provider->capabilities())->toBe($custom);
});
