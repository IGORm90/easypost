<?php

use App\Social\Contracts\ProviderErrorAdapter;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\ProviderTransientException;
use App\Social\Providers\ProviderCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/*
 * The recipe every provider call goes through, proven once here instead of
 * once per provider: send, swallow the transport failure without leaking
 * it, refuse a redirect, ask the network's adapter whether it failed, decode.
 *
 * The adapter is a stub on purpose — per-network error parsing is proven by
 * each provider's own test; this file only pins the order and the policy.
 */

/**
 * @param  Closure(Response): bool  $failed
 * @return ProviderErrorAdapter&object{asked: list<array{string, bool}>, failedCalls: int}
 */
function stubAdapter(Closure $failed, ?ProviderException $error = null): ProviderErrorAdapter
{
    return new class($failed, $error) implements ProviderErrorAdapter
    {
        /** @var list<array{string, bool}> */
        public array $asked = [];

        public int $failedCalls = 0;

        public function __construct(private readonly Closure $failed, private readonly ?ProviderException $error) {}

        public function failed(Response $response): bool
        {
            $this->failedCalls++;

            return ($this->failed)($response);
        }

        public function errorFor(Response $response, string $context, bool $auth): ProviderException
        {
            $this->asked[] = [$context, $auth];

            return $this->error ?? new ProviderPermanentException("stub failure while {$context}");
        }
    };
}

function apiCall(string $url = 'https://api.example.test/ok'): Closure
{
    return fn (): Response => Http::withOptions(['allow_redirects' => false])->get($url);
}

it('returns the decoded JSON body when the adapter reports no failure', function () {
    Http::fake(['api.example.test/*' => Http::response(['id' => 7, 'ok' => true])]);

    $body = (new ProviderCall('Example', stubAdapter(fn () => false)))->json(apiCall(), 'fetching');

    expect($body)->toBe(['id' => 7, 'ok' => true]);
});

it('coerces a body that is not an array to an empty array', function () {
    Http::fake(['api.example.test/*' => Http::response('plain text', 200)]);

    expect((new ProviderCall('Example', stubAdapter(fn () => false)))->json(apiCall(), 'fetching'))->toBe([]);
});

it('returns a bare JSON list untouched through raw()', function () {
    Http::fake(['api.example.test/*' => Http::response([['id' => 1], ['id' => 2]])]);

    $body = (new ProviderCall('Example', stubAdapter(fn () => false)))->raw(apiCall(), 'listing');

    expect($body)->toBe([['id' => 1], ['id' => 2]]);
});

it('turns a connection failure into a transient error without attaching the original', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: GET https://api.example.test/ok?access_token=SECRET'));

    try {
        (new ProviderCall('Example', stubAdapter(fn () => false)))->json(apiCall(), 'fetching');
        test()->fail('Expected a ProviderTransientException.');
    } catch (ProviderTransientException $e) {
        expect($e->getMessage())->toBe('Network error while calling the Example API.')
            ->and($e->getMessage())->not->toContain('SECRET')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('refuses a redirect before the adapter ever sees the response', function () {
    Http::fake(['api.example.test/*' => Http::response('SECRET-BODY', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);
    $adapter = stubAdapter(fn () => true);

    try {
        (new ProviderCall('Example', $adapter))->json(apiCall(), 'fetching');
        test()->fail('Expected a ProviderPermanentException.');
    } catch (ProviderPermanentException $e) {
        expect($e->getMessage())->toContain('Example')->toContain('302');
        expect($e->getMessage())->not->toContain('169.254');
        expect($e->getMessage())->not->toContain('SECRET-BODY');
    }

    expect($adapter->failedCalls)->toBe(0);
    Http::assertSentCount(1);
});

it('throws what the adapter builds, passing the context and the auth flag through', function () {
    Http::fake(['api.example.test/*' => Http::response(['error' => 'nope'], 429)]);
    $limit = new ProviderRateLimitException('slow down', 30);
    $adapter = stubAdapter(fn (Response $r) => $r->status() === 429, $limit);

    try {
        (new ProviderCall('Example', $adapter))->json(apiCall(), 'creating a session', auth: true);
        test()->fail('Expected the adapter exception.');
    } catch (ProviderRateLimitException $e) {
        expect($e)->toBe($limit);
    }

    expect($adapter->asked)->toBe([['creating a session', true]]);
});

it('defaults the auth flag to false', function () {
    Http::fake(['api.example.test/*' => Http::response([], 500)]);
    $adapter = stubAdapter(fn () => true, new ProviderAuthException('rejected'));

    expect(fn () => (new ProviderCall('Example', $adapter))->json(apiCall(), 'fetching'))->toThrow(ProviderAuthException::class);
    expect($adapter->asked)->toBe([['fetching', false]]);
});

it('lets the adapter call a 200 a failure', function () {
    Http::fake(['api.example.test/*' => Http::response(['ok' => false, 'description' => 'chat not found'])]);
    $adapter = stubAdapter(fn (Response $r) => ($r->json('ok') ?? false) !== true);

    expect(fn () => (new ProviderCall('Example', $adapter))->json(apiCall(), 'sending'))->toThrow(ProviderPermanentException::class);
});

it('also asks the adapter on raw() calls', function () {
    Http::fake(['api.example.test/*' => Http::response([], 503)]);
    $adapter = stubAdapter(fn (Response $r) => ! $r->successful(), new ProviderTransientException('down'));

    expect(fn () => (new ProviderCall('Example', $adapter))->raw(apiCall(), 'listing'))->toThrow(ProviderTransientException::class);
});

it('runs the request exactly once, even when it fails', function () {
    Http::fake(['api.example.test/*' => Http::response([], 500)]);

    try {
        (new ProviderCall('Example', stubAdapter(fn () => true)))->json(apiCall(), 'fetching');
    } catch (ProviderException) {
    }

    Http::assertSentCount(1);
});

it('refuses the whole 3xx range with the exact message, and nothing outside it', function (int $status, bool $refused) {
    Http::fake(['api.example.test/*' => Http::response('', $status)]);
    $adapter = stubAdapter(fn () => false);
    $call = new ProviderCall('Example', $adapter);

    if ($refused) {
        try {
            $call->json(apiCall(), 'fetching');
            test()->fail('Expected a ProviderPermanentException.');
        } catch (ProviderPermanentException $e) {
            expect($e->getMessage())->toBe("The Example API answered with an unexpected redirect ({$status}), which is never followed.");
        }

        return;
    }

    $call->json(apiCall(), 'fetching');
    expect($adapter->failedCalls)->toBe(1);
})->with([
    '299 passes through' => [299, false],
    '300 is refused' => [300, true],
    '399 is refused' => [399, true],
    '400 passes through' => [400, false],
]);

it('returns null from raw() when the body is not JSON', function () {
    Http::fake(['api.example.test/*' => Http::response('', 201)]);

    expect((new ProviderCall('Example', stubAdapter(fn () => false)))->raw(apiCall(), 'uploading'))->toBeNull();
});
