<?php

/*
 * The production host is a 4 GB VPS (DEPLOY.md, "Бюджет памяти"). Sixteen
 * Horizon processes do not fit next to Postgres, Redis and MinIO there, and an
 * OOM kill of a worker in the middle of a publish is how a post goes out twice.
 *
 * ComposeServicesTest sizes `horizon.mem_limit` from whatever this config
 * says, so it passes for 15 workers and for 4 alike: it cannot notice that the
 * count itself moved. This test pins the count.
 *
 * `minProcesses` must equal `maxProcesses` on every supervisor, not just sit
 * below it: with `balance: off` the standing count is
 * `min(max, max(min, queueSize))`, so an unset (default 1) minimum makes an
 * idle supervisor run ONE process regardless of `maxProcesses` — see the
 * "Post-review correction" in config/horizon.php.
 */
it('runs the standing worker counts the 4 GB host is sized for', function () {
    $supervisors = config('horizon.environments.*');

    $standing = array_map(fn (array $supervisor) => $supervisor['maxProcesses'], $supervisors);

    expect($standing)->toBe([
        'supervisor-publish' => 2,
        'supervisor-default' => 1,
        'supervisor-mail' => 1,
    ]);
});

it('keeps minProcesses equal to maxProcesses so the count is standing, not a ceiling', function (string $name) {
    $supervisor = config("horizon.environments.*.{$name}");

    expect($supervisor['balance'])->toBe('off')
        ->and($supervisor['minProcesses'])->toBe($supervisor['maxProcesses']);
})->with(['supervisor-publish', 'supervisor-default', 'supervisor-mail']);
