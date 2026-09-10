<?php

use Illuminate\Support\Facades\Log;

/*
 * LOG_PLAN.md Phase 1.1: the default text formatter is unusable
 * downstream — Loki (or any log shipper) needs one JSON object per line
 * with fields it can extract. Each production channel is asserted end to
 * end against a real stream (a temp file standing in for stdout/stderr),
 * not just against config shape, so a formatter/handler mismatch that
 * config alone wouldn't catch fails here.
 */
function tempLogPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'easypost-log-');
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

/**
 * @return array<string, mixed>
 */
function readJsonLine(string $path): array
{
    $line = trim((string) file_get_contents($path));

    return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
}

test('the app channel writes a single redacted JSON line', function () {
    $path = tempLogPath();
    config(['logging.channels.app.with.stream' => $path]);

    Log::channel('app')->info('test.app_event', ['foo' => 'bar', 'access_token' => 'secret']);

    $record = readJsonLine($path);

    expect($record['message'])->toBe('test.app_event')
        ->and($record['level_name'])->toBe('INFO')
        ->and($record['context']['foo'])->toBe('bar')
        ->and($record['context']['access_token'])->toBe('[redacted]');
});

test('the app channel level is env-driven and drops records below it', function () {
    // Local .env sets LOG_LEVEL=debug (nobody wants a filtered dev log),
    // so this asserts the LEVEL CONFIG IS RESPECTED rather than asserting
    // a literal 'info' default that would be false in every environment
    // that actually sets LOG_LEVEL.
    $path = tempLogPath();
    config([
        'logging.channels.app.with.stream' => $path,
        'logging.channels.app.level' => 'info',
    ]);

    Log::channel('app')->debug('test.should_be_dropped');

    expect(trim((string) file_get_contents($path)))->toBe('');
});

test('the errors channel is hardcoded to error level regardless of LOG_LEVEL', function () {
    $path = tempLogPath();
    config([
        'logging.channels.errors.with.stream' => $path,
        'logging.channels.errors.level' => 'error',
    ]);

    Log::channel('errors')->warning('test.should_be_dropped');
    expect(trim((string) file_get_contents($path)))->toBe('');

    Log::channel('errors')->error('test.error_event', ['reason' => 'boom']);
    $record = readJsonLine($path);

    expect($record['message'])->toBe('test.error_event')
        ->and($record['level_name'])->toBe('ERROR');
});

test('the jobs channel writes redacted JSON at info level', function () {
    $path = tempLogPath();
    config(['logging.channels.jobs.with.stream' => $path]);

    Log::channel('jobs')->info('job.processed', ['queue' => 'publish', 'password' => 'x']);

    $record = readJsonLine($path);

    expect($record['message'])->toBe('job.processed')
        ->and($record['context']['queue'])->toBe('publish')
        ->and($record['context']['password'])->toBe('[redacted]');
});
