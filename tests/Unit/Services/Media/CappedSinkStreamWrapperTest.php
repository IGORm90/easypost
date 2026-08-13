<?php

use App\Services\Media\Exceptions\SinkSizeLimitExceededException;
use App\Services\Media\Support\CappedSinkStreamWrapper;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;

/*
 * Review finding 5 (Important): covers CappedSinkStreamWrapper against a
 * REAL local HTTP server and REAL cURL. Http::fake() (used by every
 * other test in this suite covering import-by-URL) never reaches cURL
 * at all -- Laravel's Http::fake() builds its own stub middleware, never
 * touching Guzzle's real transport -- so it cannot prove this mechanism
 * survives a future Guzzle version. The mechanism it replaced
 * (CURLOPT_PROGRESSFUNCTION) is documented as deprecated for removal in
 * Guzzle 8; this test is what makes a regression on either mechanism
 * visible.
 *
 * A first replacement attempt used a `Psr\Http\Message\StreamInterface`
 * sink object. It worked correctly here, against real cURL -- but broke
 * every from-url Feature test, because Laravel's OWN `Http::fake()` sink
 * stub (`PendingRequest::sinkStubHandler()`) calls `fwrite($sink, $body)`
 * unconditionally, which requires a genuine resource, not an object.
 * CappedSinkStreamWrapper is a PHP user stream wrapper instead, so
 * `CappedSinkStreamWrapper::open()` returns a real resource compatible
 * with both consumers -- this file proves the real-cURL side; the
 * existing Feature tests in tests/Feature/Media/UploadMediaTest.php
 * (which all use Http::fake()) prove the sink-stub side keeps working,
 * and the last test below proves it directly too.
 *
 * This is a Unit test of the reusable stream primitive itself, not of
 * MediaUploadService::download() end to end: App\Support\SafeRemoteHost
 * unconditionally rejects loopback addresses by design (see its own
 * test suite), so a request to 127.0.0.1 can never reach the real
 * SSRF-guarded pipeline. Testing the primitive directly against a real
 * transfer, via a raw GuzzleHttp\Client (the same "sink" option
 * MediaUploadService::download() configures Laravel's Http facade
 * with), proves the mechanism works without weakening that guard for
 * the sake of one test.
 *
 * Server lifecycle is a plain helper function (withFixtureServer()),
 * not beforeEach()/afterEach() state on `$this` -- Pest's `$this`
 * inside a test closure is, per Larastan's known limitation, not
 * statically resolvable to this project's real TestCase (see
 * phpstan.neon's waiver for the adjacent "undefined method" shape of
 * the same limitation), so dynamic properties on it are similarly
 * invisible to static analysis. A helper function sidesteps that
 * entirely, the same way tests/Pest.php's raceConcurrently() does.
 */

/**
 * @param  Closure(int): mixed  $callback
 */
function withFixtureServer(Closure $callback): mixed
{
    $port = 8199 + (getmypid() % 300);
    $script = __DIR__.'/../../../Fixtures/media_oversized_body_server.php';

    $process = proc_open(
        ['php', '-S', "127.0.0.1:{$port}", $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        test()->fail('Could not start the fixture HTTP server.');
    }

    $deadline = microtime(true) + 5;
    $connected = false;

    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

        if ($conn !== false) {
            fclose($conn);
            $connected = true;
            break;
        }

        usleep(50_000);
    }

    if (! $connected) {
        proc_terminate($process);
        proc_close($process);
        test()->fail('Fixture HTTP server never became reachable.');
    }

    try {
        return $callback($port);
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

it('aborts a real transfer the instant cumulative bytes exceed the cap, never writing past it', function () {
    withFixtureServer(function (int $port) {
        $tmp = tempnam(sys_get_temp_dir(), 'sink-cap-test-');
        $sink = CappedSinkStreamWrapper::open($tmp, 1000);

        $client = new Client;

        try {
            $client->request('GET', "http://127.0.0.1:{$port}/", [
                'sink' => $sink,
                'allow_redirects' => false,
            ]);
            test()->fail('Expected the real transfer to be aborted by CappedSinkStreamWrapper.');
        } catch (SinkSizeLimitExceededException $e) {
            expect($e->getMessage())->toContain('1000-byte cap');
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        // The invariant this class exists for: the cap is checked
        // BEFORE delegating a chunk's write, so disk contents can never
        // exceed it -- and, since the fixture serves 5 MB, this also
        // proves the transfer was genuinely aborted early, not merely
        // detected after the full body had already landed.
        clearstatcache();
        expect(filesize($tmp))->toBeLessThanOrEqual(1000);

        @unlink($tmp);
    });
});

it('does not disturb a real transfer that stays within the cap', function () {
    withFixtureServer(function (int $port) {
        $tmp = tempnam(sys_get_temp_dir(), 'sink-cap-test-');
        $sink = CappedSinkStreamWrapper::open($tmp, 10 * 1024 * 1024);

        $client = new Client;
        $response = $client->request('GET', "http://127.0.0.1:{$port}/", [
            'sink' => $sink,
            'allow_redirects' => false,
        ]);

        if (is_resource($sink)) {
            fclose($sink);
        }

        clearstatcache();
        expect($response->getStatusCode())->toBe(200)
            ->and(filesize($tmp))->toBe(5 * 1024 * 1024);

        @unlink($tmp);
    });
});

it('is also usable as the sink for Http::fake() (Laravel\'s own fwrite()-based stub, not just real cURL)', function () {
    // The specific compatibility gap the first (StreamInterface-based)
    // attempt failed on: PendingRequest::sinkStubHandler() calls
    // fwrite($sink, $body) directly. A resource from
    // CappedSinkStreamWrapper::open() must survive that call untouched
    // for every existing Http::fake()-based Feature test to keep
    // passing.
    Http::fake([
        'fake.example/*' => Http::response(str_repeat('a', 5000), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $tmp = tempnam(sys_get_temp_dir(), 'sink-cap-test-');
    $sink = CappedSinkStreamWrapper::open($tmp, 10 * 1024 * 1024);

    $response = Http::withOptions(['sink' => $sink, 'allow_redirects' => false])->get('http://fake.example/photo.jpg');

    if (is_resource($sink)) {
        fclose($sink);
    }

    clearstatcache();
    expect($response->status())->toBe(200)
        ->and(filesize($tmp))->toBe(5000);

    @unlink($tmp);
});
