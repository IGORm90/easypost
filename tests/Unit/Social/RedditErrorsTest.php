<?php

use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Providers\RedditErrors;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/*
 * How Reddit's HTTP failures map onto the shared exception taxonomy,
 * proven once for the adapter. RedditProviderTest still proves the same
 * behaviour end to end through Http::fake; this pins the decision table
 * and the message text. (Reddit's RATELIMIT-inside-a-200 envelope is not
 * an HTTP failure and stays in RedditProvider, so failed() is status-only.)
 */

/**
 * @param  array<string, string>  $headers
 */
function redditResponse(int $status, array $headers = []): Response
{
    return new Response(Http::response([], $status, $headers)->wait());
}

it('treats any non-2xx as failed and any 2xx as fine', function () {
    $adapter = new RedditErrors;

    expect($adapter->failed(redditResponse(200)))->toBeFalse();
    expect($adapter->failed(redditResponse(201)))->toBeFalse();
    expect($adapter->failed(redditResponse(404)))->toBeTrue();
    expect($adapter->failed(redditResponse(500)))->toBeTrue();
});

it('maps a content call by HTTP status', function (int $status, string $class) {
    $error = (new RedditErrors)->errorFor(redditResponse($status), 'submitting the post', false);

    expect($error)->toBeInstanceOf($class)
        ->and($error->getMessage())->toBe("Reddit API error while submitting the post ({$status}).");
})->with([
    'rate limited' => [429, ProviderRateLimitException::class],
    'unauthorised' => [401, ProviderAuthException::class],
    'forbidden' => [403, ProviderAuthException::class],
    'server error' => [500, ProviderTransientException::class],
    'bad request' => [400, ProviderPermanentException::class],
]);

it('carries Retry-After on a content-call rate limit', function () {
    $error = (new RedditErrors)->errorFor(redditResponse(429, ['Retry-After' => '30']), 'submitting the post', false);

    expect($error)->toBeInstanceOf(ProviderRateLimitException::class);
    assert($error instanceof ProviderRateLimitException);
    expect($error->retryAfterSeconds)->toBe(30);
});

it('reads every non-429, non-5xx rejection on an auth call as the credential being bad', function (int $status) {
    $error = (new RedditErrors)->errorFor(redditResponse($status), 'refreshing the token', true);

    expect($error)->toBeInstanceOf(ProviderAuthException::class)
        ->and($error->getMessage())->toBe('Reddit rejected the request while refreshing the token.');
})->with([400, 401, 403, 404]);

it('still reads 429 and 5xx on an auth call as retryable', function () {
    $limited = (new RedditErrors)->errorFor(redditResponse(429, ['Retry-After' => '12']), 'refreshing the token', true);
    $down = (new RedditErrors)->errorFor(redditResponse(503), 'refreshing the token', true);

    expect($limited)->toBeInstanceOf(ProviderRateLimitException::class);
    assert($limited instanceof ProviderRateLimitException);
    expect($limited->retryAfterSeconds)->toBe(12);
    expect($limited->getMessage())->toBe('Reddit rate-limited refreshing the token.');
    expect($down)->toBeInstanceOf(ProviderTransientException::class);
    expect($down->getMessage())->toBe('Reddit API error while refreshing the token.');
});
