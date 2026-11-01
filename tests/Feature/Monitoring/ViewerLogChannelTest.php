<?php

use Illuminate\Support\Facades\Log;

/*
 * The `viewer` channel exists for Log Viewer, which reads dated plain-text
 * files in Laravel's own line format — the JSON on stdout is not parseable
 * by it. Two properties matter and neither is visible in config:
 *
 *  1. It must redact. Laravel's `daily`/`single` drivers silently ignore a
 *     `processors` key (only the `monolog` driver applies it), so the
 *     obvious config — a daily channel plus RedactSensitiveContext — writes
 *     channel tokens to disk in clear with no error anywhere.
 *  2. Its lines must stay in `[Y-m-d H:i:s] env.LEVEL: message {context}`
 *     form, or the viewer shows an unparsed wall of text.
 */
function viewerLogDir(): string
{
    $dir = sys_get_temp_dir().'/easypost-viewer-'.bin2hex(random_bytes(4));
    mkdir($dir);
    register_shutdown_function(static function () use ($dir) {
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
    });

    return $dir;
}

it('writes a dated file in Laravel line format with secrets redacted', function () {
    $dir = viewerLogDir();
    config(['logging.channels.viewer.with.filename' => "{$dir}/viewer.log"]);

    Log::channel('viewer')->info('publish.attempt', [
        'post_group_id' => 'group-1',
        'access_token' => 'plain-secret-value',
        'headers' => ['Authorization' => 'Bearer plain-secret-value'],
    ]);

    $files = glob("{$dir}/viewer-*.log") ?: [];
    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toBe('viewer-'.date('Y-m-d').'.log');

    $line = trim((string) file_get_contents($files[0]));

    expect($line)->toMatch('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \w+\.INFO: publish\.attempt \{/')
        ->and($line)->toContain('"post_group_id":"group-1"')
        ->and($line)->toContain('[redacted]')
        ->and($line)->not->toContain('plain-secret-value');
});

it('honours LOG_LEVEL', function () {
    $dir = viewerLogDir();
    config([
        'logging.channels.viewer.with.filename' => "{$dir}/viewer.log",
        'logging.channels.viewer.level' => 'warning',
    ]);

    Log::channel('viewer')->info('below.threshold');
    Log::channel('viewer')->warning('at.threshold');

    // The warning proves the channel writes at all; without it an empty
    // directory would also "pass" for a channel that never works.
    $files = glob("{$dir}/viewer-*.log") ?: [];
    expect($files)->toHaveCount(1);

    $contents = (string) file_get_contents($files[0]);
    expect($contents)->toContain('at.threshold')
        ->and($contents)->not->toContain('below.threshold');
});

/*
 * Three kinds of process append to the same dated file: php-fpm workers
 * (www-data), and horizon and the scheduler (root). Whichever writes first
 * creates the file, and a root-created 0644 file cannot be appended to by
 * www-data, so web requests would start throwing on every log call. The
 * handler therefore chmods the file it creates; chmod ignores the umask.
 */
it('creates its file writable by every container user', function () {
    $dir = viewerLogDir();
    config(['logging.channels.viewer.with.filename' => "{$dir}/viewer.log"]);

    Log::channel('viewer')->warning('permission.probe');

    $file = (glob("{$dir}/viewer-*.log") ?: [])[0];

    expect(decoct(fileperms($file) & 0777))->toBe('666');
});
