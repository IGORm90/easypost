<?php

use App\Models\Integration;
use App\Social\Dto\PostPayloadDto;
use App\Social\Dto\ProviderCapabilities;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPartialFailureException;
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

it('publishes a chain and records one result per segment, keyed by index', function () {
    $provider = fakeProvider();
    $integration = new Integration;
    $chain = [new PostPayloadDto('root'), new PostPayloadDto('reply')];

    $results = $provider->post($integration, $chain);

    expect($results)->toHaveCount(2)
        ->and($results[0]->releaseId)->toStartWith('fake-release-')
        ->and($results[1]->releaseId)->toStartWith('fake-release-')
        ->and($results[0]->releaseId)->not->toBe($results[1]->releaseId)
        ->and($results[1]->releaseUrl)->toContain($results[1]->releaseId)
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
    $results = $provider->post($integration, $chain);
    expect($results[0]->releaseId)->toStartWith('fake-release-');
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
        test()->fail('expected ProviderRateLimitException');
    } catch (ProviderRateLimitException $e) {
        expect($e->retryAfterSeconds)->toBe(42)
            ->and($e->getMessage())->toBe('slow down');
    }
});

it('throws a partial-failure exception carrying results for the segments that already published', function () {
    $provider = fakeProvider()->failNextPostWithPartialFailure(2, 'third segment rejected');
    $integration = new Integration;
    $chain = [
        new PostPayloadDto('root'),
        new PostPayloadDto('reply 1'),
        new PostPayloadDto('reply 2'),
    ];

    try {
        $provider->post($integration, $chain);
        test()->fail('expected ProviderPartialFailureException');
    } catch (ProviderPartialFailureException $e) {
        expect($e->getMessage())->toBe('third segment rejected')
            ->and($e->published)->toHaveCount(2)
            ->and($e->published)->toHaveKeys([0, 1])
            ->and($e->published[0]->releaseId)->toStartWith('fake-release-')
            ->and($e->published[1]->releaseId)->toStartWith('fake-release-')
            ->and($e->published[0]->releaseId)->not->toBe($e->published[1]->releaseId);
    }

    // a partially-failed chain is not recorded as a fully published chain
    expect($provider->publishedChains())->toBe([]);
});

it('resumes a partially-failed thread by attaching the continuation to the anchor, without publishing a second root', function () {
    $provider = fakeProvider();
    $integration = new Integration;
    $originalChain = [
        new PostPayloadDto('root'),
        new PostPayloadDto('reply 1'),
        new PostPayloadDto('reply 2'),
    ];

    // First attempt: only the root segment publishes, then the chain fails.
    $provider->failNextPostWithPartialFailure(1, 'second segment rejected');

    $rootResult = null;
    try {
        $provider->post($integration, $originalChain);
        test()->fail('expected ProviderPartialFailureException');
    } catch (ProviderPartialFailureException $e) {
        $rootResult = $e->published[0];
    }

    expect($rootResult)->not->toBeNull()
        ->and($provider->rootReleaseIds())->toBe([$rootResult->releaseId]);

    // Pipeline resumes: a NEW chain containing only the unpublished
    // segments, first element carrying the anchor to the root that DID
    // publish. This must NOT create a second root.
    $resumedChain = [
        $originalChain[1]->withReplyToReleaseId($rootResult->releaseId),
        $originalChain[2],
    ];

    $results = $provider->post($integration, $resumedChain);

    expect($results)->toHaveCount(2)
        // exactly one root across BOTH post() calls — the resumed
        // chain's first segment was attached, not re-rooted
        ->and($provider->rootReleaseIds())->toBe([$rootResult->releaseId])
        ->and($provider->rootReleaseIds())->toHaveCount(1);

    // and the fake actually recorded the anchor it was given
    $segments = $provider->publishedSegments();
    $resumedFirstSegment = $segments[1]; // index 0 was the root from the first call
    expect($resumedFirstSegment['attachedTo'])->toBe($rootResult->releaseId)
        ->and($resumedFirstSegment['releaseId'])->toBe($results[0]->releaseId);
});

it('refuses a reply-to-release-id it cannot honour instead of publishing a duplicate root', function () {
    $provider = fakeProvider()->refuseReplyToReleaseId();
    $integration = new Integration;
    $chain = [
        (new PostPayloadDto('continuation'))->withReplyToReleaseId('some-external-id'),
    ];

    expect(fn () => $provider->post($integration, $chain))
        ->toThrow(ProviderPermanentException::class);

    // nothing was published — no duplicate, no partial state
    expect($provider->publishedSegments())->toBe([]);
});

it('lets tests override capabilities, including the newly required fields', function () {
    $provider = fakeProvider();
    $custom = new ProviderCapabilities(
        maxCharacters: 280,
        maxImages: 0,
        maxVideos: 0,
        supportsThreads: false,
        supportsScheduling: false,
        allowedMimeTypes: [],
        maxFileSizeBytes: 0,
        allowsMixedMedia: false,
        maxThreadLength: 1,
        requiresAltText: true,
    );

    $provider->withCapabilities($custom);

    expect($provider->capabilities())->toBe($custom)
        ->and($provider->capabilities()->requiresAltText)->toBeTrue()
        ->and($provider->capabilities()->maxThreadLength)->toBe(1);
});

it('defaults requiresAltText to false when not specified', function () {
    $capabilities = fakeProvider()->capabilities();

    expect($capabilities->requiresAltText)->toBeFalse()
        ->and($capabilities->allowsMixedMedia)->toBeTrue()
        ->and($capabilities->maxThreadLength)->toBeNull();
});
