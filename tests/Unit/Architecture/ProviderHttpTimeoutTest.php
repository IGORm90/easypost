<?php

/**
 * Final-review Critical 3, made executable: every outbound HTTP call a
 * provider adapter makes must go through
 * `App\Social\Providers\BoundsHttpTimeouts`' `http()`/`uploadHttp()`,
 * never the `Http` facade directly — a bare `Http::post(...)` silently
 * inherits Laravel's 30-second default timeout, and
 * `PublishService::publishChannel()` holds a transaction (and row locks
 * on the segments being sent) open across the whole call, inside a
 * worker whose own timeout is 90s. Four calls per segment on Slack's
 * media path is all it takes to blow through that.
 *
 * A rule about a string of text in a file, like the two arch tests
 * beside this one, so it is checked by grep rather than by an `arch()`
 * preset (which understands class/namespace structure, not call sites).
 * grep's exit code is inspected explicitly — 0 matched, 1 ran fine with
 * no match, >1 a real failure — so "the search itself broke" can never
 * pass as green.
 */
it('never calls the Http facade directly from a provider adapter', function () {
    $root = base_path('app/Social/Providers');
    expect(is_dir($root))->toBeTrue("search root [{$root}] does not exist — the guard cannot have run");

    $command = sprintf(
        'grep -rn %s %s --include=%s',
        escapeshellarg('Http::'),
        escapeshellarg($root),
        escapeshellarg('*.php'),
    );

    $outputLines = [];
    $exitCode = null;
    exec($command, $outputLines, $exitCode);

    if ($exitCode > 1) {
        throw new RuntimeException(
            "grep failed while scanning app/Social/Providers for Http:: (exit code {$exitCode}): "
            .implode("\n", $outputLines),
        );
    }

    // BoundsHttpTimeouts is the one place allowed to touch the facade —
    // it is what applies the timeouts.
    $violations = array_values(array_filter(
        $outputLines,
        fn (string $line): bool => ! str_contains($line, 'BoundsHttpTimeouts.php'),
    ));

    expect($violations)->toBe([], "these provider call sites bypass BoundsHttpTimeouts and inherit Laravel's 30s default timeout:\n".implode("\n", $violations));
});
