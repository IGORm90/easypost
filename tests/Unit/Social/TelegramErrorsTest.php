<?php

use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Providers\TelegramErrors;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/*
 * How Telegram's Bot API failures map onto the shared exception taxonomy,
 * proven once for the adapter. TelegramProviderTest still proves the same
 * behaviour end to end through Http::fake. What is different here: success
 * is the body's `ok: true` (a failure can arrive over HTTP 200), the error
 * code comes from the body before the HTTP status, and the auth-call
 * messages are fixed phrases that ignore the caller's context.
 */

/**
 * @param  array<string, mixed>|string  $body
 */
function telegramResponse(int $status, array|string $body = []): Response
{
    return new Response(Http::response($body, $status)->wait());
}

it('treats the body, not the HTTP status, as the verdict', function (int $status, array|string $body, bool $failed) {
    expect((new TelegramErrors)->failed(telegramResponse($status, $body)))->toBe($failed);
})->with([
    'ok true' => [200, ['ok' => true, 'result' => []], false],
    'ok false over a 200' => [200, ['ok' => false, 'error_code' => 400], true],
    'ok false over a 400' => [400, ['ok' => false], true],
    'no ok field' => [200, ['result' => []], true],
    'ok is not literally true' => [200, ['ok' => 'true'], true],
    'not JSON' => [200, 'gateway html', true],
]);

it('maps a content call by the body\'s error code and includes Telegram\'s description', function (int $code, string $class) {
    $response = telegramResponse(200, ['ok' => false, 'error_code' => $code, 'description' => 'chat not found']);

    $error = (new TelegramErrors)->errorFor($response, 'sending the message', false);

    expect($error)->toBeInstanceOf($class)
        ->and($error->getMessage())->toBe('Telegram API error while sending the message: chat not found');
})->with([
    'rate limited' => [429, ProviderRateLimitException::class],
    'unauthorised' => [401, ProviderAuthException::class],
    'forbidden' => [403, ProviderAuthException::class],
    'server error' => [502, ProviderTransientException::class],
    'bad request' => [400, ProviderPermanentException::class],
]);

it('falls back to the HTTP status when the body has no error code, and to unknown error without a description', function () {
    $error = (new TelegramErrors)->errorFor(telegramResponse(403, ['ok' => false]), 'sending', false);

    expect($error)->toBeInstanceOf(ProviderAuthException::class)
        ->and($error->getMessage())->toBe('Telegram API error while sending: unknown error');
});

it('reads an ok:false that carries no error code over a 200 as transient', function () {
    $error = (new TelegramErrors)->errorFor(telegramResponse(200, ['ok' => false]), 'sending', false);

    expect($error)->toBeInstanceOf(ProviderTransientException::class);
});

it('takes the retry hint from parameters.retry_after', function () {
    $response = telegramResponse(200, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 17]]);

    $error = (new TelegramErrors)->errorFor($response, 'sending', false);

    expect($error)->toBeInstanceOf(ProviderRateLimitException::class);
    assert($error instanceof ProviderRateLimitException);
    expect($error->retryAfterSeconds)->toBe(17);
});

it('reads every non-429, non-5xx rejection on an auth call as the credentials being bad, ignoring the context', function (int $code) {
    $response = telegramResponse(200, ['ok' => false, 'error_code' => $code, 'description' => 'Forbidden: bot was kicked']);

    $error = (new TelegramErrors)->errorFor($response, 'a context that must not appear', true);

    expect($error)->toBeInstanceOf(ProviderAuthException::class)
        ->and($error->getMessage())->toBe('Telegram rejected the provided bot token/chat id: Forbidden: bot was kicked');
})->with([400, 401, 403, 404]);

it('still reads 429 and 5xx on an auth call as retryable, with fixed phrases', function () {
    $limited = (new TelegramErrors)->errorFor(telegramResponse(200, ['ok' => false, 'error_code' => 429, 'description' => 'slow', 'parameters' => ['retry_after' => 9]]), 'ignored', true);
    $down = (new TelegramErrors)->errorFor(telegramResponse(200, ['ok' => false, 'error_code' => 500, 'description' => 'oops']), 'ignored', true);

    expect($limited)->toBeInstanceOf(ProviderRateLimitException::class);
    assert($limited instanceof ProviderRateLimitException);
    expect($limited->retryAfterSeconds)->toBe(9);
    expect($limited->getMessage())->toBe('Telegram rate-limited chat verification: slow');
    expect($down)->toBeInstanceOf(ProviderTransientException::class);
    expect($down->getMessage())->toBe('Telegram API error while verifying credentials: oops');
});
