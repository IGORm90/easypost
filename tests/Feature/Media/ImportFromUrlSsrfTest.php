<?php

use App\Services\Media\Exceptions\MediaImportException;
use App\Services\Media\MediaUploadService;
use App\Support\SafeRemoteHost;
use Illuminate\Support\Facades\Http;

/*
 * Final-review batch 2, Critical 1 — the SSRF the reviewer proved live,
 * re-run here against REAL cURL and a REAL loopback HTTP server through
 * the REAL `MediaUploadService::download()`.
 *
 * Why not `Http::fake()`: a faked client never reaches cURL, and cURL's
 * own inet_aton-style host parsing IS the defect — `0177.0.0.1` is
 * rejected by `filter_var(FILTER_VALIDATE_IP)`, resolved by this
 * container's DNS to the public `177.0.0.1` (verified: it really does
 * answer, this is not hypothetical), approved by the guard, and then
 * connected by cURL to `127.0.0.1`. Only a real transfer can show that.
 *
 * Why not "assert an exception is thrown": before the fix, several of
 * these hosts threw anyway — because this machine's resolver happened to
 * answer NXDOMAIN for them, which is an accident of the environment, not
 * a property of the guard. So every case below asserts on the loopback
 * server's BODY: the marker must never come back. The first test is the
 * control that makes the rest meaningful — it proves the server is
 * genuinely up and genuinely fetchable by cURL at that address, so a
 * later "blocked" can never be a dead server in disguise.
 */

/**
 * @param  Closure(int): mixed  $callback
 */
function withLoopbackSecretServer(Closure $callback): mixed
{
    $port = 8600 + (getmypid() % 300);
    $script = __DIR__.'/../../Fixtures/media_loopback_secret_server.php';

    $process = proc_open(
        ['php', '-S', "127.0.0.1:{$port}", $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        test()->fail('Could not start the loopback fixture server.');
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
        test()->fail('Loopback fixture server never became reachable.');
    }

    try {
        return $callback($port);
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

/**
 * Runs the production download path for $url and returns either the
 * retrieved body or the string 'blocked'. Deliberately reflects into the
 * private method rather than going through the HTTP route: this is about
 * what the downloader connects to, and the route adds auth/quota/storage
 * noise that would obscure a false negative.
 */
function attemptImport(string $url): string
{
    $service = app(MediaUploadService::class);
    $download = new ReflectionMethod($service, 'download');

    try {
        $path = $download->invoke($service, $url);
    } catch (MediaImportException) {
        return 'blocked';
    }

    $body = (string) file_get_contents($path);
    @unlink($path);

    return $body;
}

it('CONTROL: the fixture server really is up and really is fetchable by cURL at 127.0.0.1', function () {
    withLoopbackSecretServer(function (int $port) {
        // Not through the guard — straight at the address, with the same
        // real client. If this ever stops returning the marker, every
        // "blocked" assertion below is meaningless and this file must be
        // fixed rather than trusted.
        $body = Http::withOptions(['allow_redirects' => false])
            ->timeout(5)
            ->get("http://127.0.0.1:{$port}/")
            ->body();

        expect($body)->toBe('INTERNAL-LOOPBACK-SECRET');
    });
});

it('never retrieves a loopback body through the real download path, for the whole IP-literal family', function () {
    withLoopbackSecretServer(function (int $port) {
        $hosts = [
            '127.0.0.1',        // the plain form, blocked before this batch too
            '0177.0.0.1',       // PROVEN LIVE pre-fix: this one returned the body
            '0x7f.0.0.1',
            '127.1',
            '017700000001',
            '2130706433',
            '0x7f000001',
        ];

        foreach ($hosts as $host) {
            expect(attemptImport("http://{$host}:{$port}/"))
                ->toBe('blocked', "[{$host}] reached the loopback server");
        }
    });
});

it('CURLOPT_RESOLVE genuinely decides where real cURL connects, which is what pinning relies on', function () {
    withLoopbackSecretServer(function (int $port) {
        // The mechanism, proven rather than assumed: a request for a
        // public hostname, pinned to the loopback address, lands on the
        // fixture server. That is why pinning the VALIDATED address into
        // the outbound request makes the checked address and the
        // connected address the same one — and it is the property the
        // whole Critical 1 / Important 6 fix rests on.
        $options = SafeRemoteHost::pinOptions('example.com', ['127.0.0.1'], $port);

        $body = Http::withOptions($options + ['allow_redirects' => false])
            ->timeout(5)
            ->get("http://example.com:{$port}/")
            ->body();

        expect($body)->toBe('INTERNAL-LOOPBACK-SECRET');
    });
});
