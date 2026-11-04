<?php

use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Providers\MetaGraphErrors;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/*
 * How Facebook Page, Instagram and Threads read Graph's error envelope,
 * proven once for the adapter all three share. The providers' own tests
 * still prove the same behaviour end to end through Http::fake; this file
 * pins the decision table and the message text in one place.
 */

/**
 * @param  array<string, string>  $headers
 */
function graphResponse(int $status, mixed $body = [], array $headers = []): Response
{
    return new Response(Http::response($body, $status, $headers)->wait());
}

it('treats any non-2xx as failed and any 2xx as fine', function () {
    $adapter = new MetaGraphErrors('Example');

    expect($adapter->failed(graphResponse(200)))->toBeFalse();
    expect($adapter->failed(graphResponse(201)))->toBeFalse();
    expect($adapter->failed(graphResponse(400)))->toBeTrue();
    expect($adapter->failed(graphResponse(503)))->toBeTrue();
});

it('maps the credential-is-dead subcodes to an auth error', function (int $subcode) {
    $response = graphResponse(400, ['error' => ['message' => 'Meta wording', 'code' => 190, 'error_subcode' => $subcode]]);

    $error = (new MetaGraphErrors('Example'))->errorFor($response, 'publishing', false);

    expect($error)->toBeInstanceOf(ProviderAuthException::class)
        ->and($error->getMessage())->toBe("The Example API rejected the credential while publishing (subcode {$subcode}).");
})->with([190, 102, 460, 463, 467]);

it('maps the rate-limit codes to a rate-limit error carrying Retry-After', function (int $code) {
    $response = graphResponse(400, ['error' => ['code' => $code]], ['Retry-After' => '45']);

    $error = (new MetaGraphErrors('Example'))->errorFor($response, 'publishing', false);

    expect($error)->toBeInstanceOf(ProviderRateLimitException::class);
    assert($error instanceof ProviderRateLimitException);
    expect($error->retryAfterSeconds)->toBe(45);
    expect($error->getMessage())->toBe('The Example API rate-limited the request while publishing.');
})->with([4, 17, 32, 613]);

it('falls back to the HTTP status for anything else', function (int $status, string $class) {
    $response = graphResponse($status, ['error' => ['code' => 100]]);

    $error = (new MetaGraphErrors('Example'))->errorFor($response, 'fetching', false);

    expect($error)->toBeInstanceOf($class)
        ->and($error->getMessage())->toBe("The Example API rejected the request while fetching ({$status}).");
})->with([
    'rate limited by status' => [429, ProviderRateLimitException::class],
    'unauthorised' => [401, ProviderAuthException::class],
    'server error' => [500, ProviderTransientException::class],
    'bad request' => [400, ProviderPermanentException::class],
]);

it('never repeats Meta wording in the exception text', function () {
    $response = graphResponse(400, ['error' => ['message' => 'SECRET-META-WORDING', 'code' => 100]]);

    $error = (new MetaGraphErrors('Example'))->errorFor($response, 'fetching', false);

    expect($error->getMessage())->not->toContain('SECRET-META-WORDING');
});

it('reads a body that is not JSON as an unknown error and still maps by status', function () {
    $error = (new MetaGraphErrors('Example'))->errorFor(graphResponse(502, 'gateway html'), 'fetching', false);

    expect($error)->toBeInstanceOf(ProviderTransientException::class);
});
