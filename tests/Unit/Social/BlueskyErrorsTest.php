<?php

use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Providers\BlueskyErrors;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/*
 * How Bluesky's XRPC failures map onto the shared exception taxonomy,
 * proven once for the adapter. BlueskyProviderTest still proves the same
 * behaviour end to end through Http::fake; this pins the decision table
 * and the message text. Unlike Meta, Bluesky's own `message` (falling back
 * to `error`) is part of the text.
 */

/**
 * @param  array<string, string>  $headers
 */
function blueskyResponse(int $status, mixed $body = [], array $headers = []): Response
{
    return new Response(Http::response($body, $status, $headers)->wait());
}

it('treats any non-2xx as failed and any 2xx as fine', function () {
    $adapter = new BlueskyErrors;

    expect($adapter->failed(blueskyResponse(200)))->toBeFalse();
    expect($adapter->failed(blueskyResponse(400)))->toBeTrue();
    expect($adapter->failed(blueskyResponse(502)))->toBeTrue();
});

it('maps a content call by HTTP status and includes Bluesky\'s message', function (int $status, string $class) {
    $response = blueskyResponse($status, ['error' => 'InvalidRequest', 'message' => 'Record too long']);

    $error = (new BlueskyErrors)->errorFor($response, 'creating the post', false);

    expect($error)->toBeInstanceOf($class)
        ->and($error->getMessage())->toBe("Bluesky API error while creating the post ({$status}): Record too long");
})->with([
    'rate limited' => [429, ProviderRateLimitException::class],
    'unauthorised' => [401, ProviderAuthException::class],
    'server error' => [500, ProviderTransientException::class],
    'bad request' => [400, ProviderPermanentException::class],
]);

it('falls back to the error code, then to unknown error, when there is no message', function () {
    $codeOnly = (new BlueskyErrors)->errorFor(blueskyResponse(400, ['error' => 'InvalidRequest']), 'posting', false);
    $neither = (new BlueskyErrors)->errorFor(blueskyResponse(400, ['x' => 1]), 'posting', false);
    $notJson = (new BlueskyErrors)->errorFor(blueskyResponse(400, 'gateway html'), 'posting', false);

    expect($codeOnly->getMessage())->toBe('Bluesky API error while posting (400): InvalidRequest');
    expect($neither->getMessage())->toBe('Bluesky API error while posting (400): unknown error');
    expect($notJson->getMessage())->toBe('Bluesky API error while posting (400): unknown error');
});

it('carries Retry-After on a content-call rate limit', function () {
    $error = (new BlueskyErrors)->errorFor(blueskyResponse(429, [], ['Retry-After' => '30']), 'posting', false);

    expect($error)->toBeInstanceOf(ProviderRateLimitException::class);
    assert($error instanceof ProviderRateLimitException);
    expect($error->retryAfterSeconds)->toBe(30);
});

it('reads every non-429, non-5xx rejection on an auth call as the credential being bad', function (int $status) {
    $response = blueskyResponse($status, ['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password']);

    $error = (new BlueskyErrors)->errorFor($response, 'creating a session', true);

    expect($error)->toBeInstanceOf(ProviderAuthException::class)
        ->and($error->getMessage())->toBe('Bluesky rejected the request while creating a session: Invalid identifier or password');
})->with([400, 401, 403, 404]);

it('still reads 429 and 5xx on an auth call as retryable', function () {
    $limited = (new BlueskyErrors)->errorFor(blueskyResponse(429, ['message' => 'slow'], ['Retry-After' => '12']), 'creating a session', true);
    $down = (new BlueskyErrors)->errorFor(blueskyResponse(503, ['message' => 'maintenance']), 'creating a session', true);

    expect($limited)->toBeInstanceOf(ProviderRateLimitException::class);
    assert($limited instanceof ProviderRateLimitException);
    expect($limited->retryAfterSeconds)->toBe(12);
    expect($limited->getMessage())->toBe('Bluesky rate-limited creating a session: slow');
    expect($down)->toBeInstanceOf(ProviderTransientException::class);
    expect($down->getMessage())->toBe('Bluesky API error while creating a session: maintenance');
});
