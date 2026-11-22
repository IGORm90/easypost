<?php

/*
 * scripts/deploy.sh, rollback.sh and backup.sh decide in what order a release
 * stops, migrates and restarts a system whose one unforgivable failure is
 * publishing a customer's post twice. Nothing in CI runs them, so the order
 * and the forbidden commands are pinned from the text. The behaviour itself
 * was rehearsed on a real stack (DEPLOY.md, stage 2).
 */

function scriptText(string $name): string
{
    $path = dirname(__DIR__, 3).'/scripts/'.$name;
    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

/** The script without comment lines, so a comment explaining a ban does not trip the ban. */
function scriptCode(string $name): string
{
    return implode("\n", array_filter(
        explode("\n", scriptText($name)),
        fn (string $line) => ! preg_match('/^\s*#(?!!)/', $line),
    ));
}

/** Position of the first occurrence of $needle, failing loudly when it is absent. */
function at(string $code, string $needle): int
{
    $position = strpos($code, $needle);
    expect($position)->not->toBeFalse("missing: {$needle}");

    return (int) $position;
}

it('fails fast and loud', function (string $script) {
    expect(scriptText($script))->toStartWith('#!/usr/bin/env bash')
        ->and(scriptCode($script))->toContain('set -euo pipefail');
})->with(['deploy.sh', 'rollback.sh', 'backup.sh']);

it('sources lib.sh next to itself, so it runs from /opt/easypost and from a checkout alike', function (string $script) {
    expect(scriptCode($script))->toContain('. "$SCRIPT_DIR/lib.sh"')
        ->and(scriptCode($script))->toContain('SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"')
        ->and(scriptCode($script))->not->toContain('/opt/easypost/scripts');
})->with(['deploy.sh', 'rollback.sh', 'backup.sh']);

it('deploys in the only safe order: pull, backup, quiesce, migrate, switch, smoke', function () {
    $code = scriptCode('deploy.sh');

    $order = [
        'dc pull --policy missing',          // an absent image aborts BEFORE anything is stopped
        '"$SCRIPT_DIR/backup.sh"',           // a restore point before the schema moves
        'artisan down',                      // the old php answers 503
        'dc stop scheduler',                 // nothing new is dispatched ...
        'dc stop horizon',                   // ... and running publishes finish (stop_grace_period)
        'migrate --force',                   // the schema moves only now
        '> "$EASYPOST_DIR/.previous-tag"',   // recorded before the switch,
        '> "$EASYPOST_DIR/.current-tag"',    // so a failed smoke cannot leave `dc` pointing at the old release
        'dc up -d',                          // the new release
        'caddy reload',                      // a bind-mounted proxy config is not re-read by itself
        'dc restart nginx',                  // the 502-on-recreated-php trap (docs/DEPLOYMENT.md)
        'https://${APP_HOST}/up',            // the smoke check
        'horizon:status',                    // and the queue is actually running
    ];

    $positions = array_map(fn (string $step) => at($code, $step), $order);
    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted, 'deploy.sh steps are out of order: '.implode(' < ', $order));
});

it('stops the scheduler before horizon, so nothing is dispatched while horizon drains', function () {
    $code = scriptCode('deploy.sh');

    expect(at($code, 'dc stop scheduler'))->toBeLessThan(at($code, 'dc stop horizon'));
});

it('lets horizon finish its publishes: a plain stop (180 s grace), never a kill or a short timeout', function (string $script) {
    $code = scriptCode($script);

    expect($code)->not->toMatch('/docker\s+kill/')
        ->and($code)->not->toMatch('/stop\s+(-t|--time)/')
        ->and($code)->not->toMatch('/\bdc\s+kill\b/')
        ->and($code)->not->toContain('--force-recreate horizon')
        ->and($code)->not->toContain('down -v');
})->with(['deploy.sh', 'rollback.sh']);

it('never runs a destructive database or key command', function (string $script) {
    $code = scriptCode($script);

    foreach (['migrate:fresh', 'migrate:rollback', 'db:wipe', 'key:generate', 'db:seed', 'DemoSeeder'] as $forbidden) {
        expect($code)->not->toContain($forbidden);
    }
})->with(['deploy.sh', 'rollback.sh', 'backup.sh']);

it('requires a release tag and asks for no root', function () {
    expect(scriptCode('deploy.sh'))->toContain('TAG="${1:?usage: deploy.sh <sha>}"')
        ->and(scriptCode('rollback.sh'))->toContain('TAG="${1:?usage: rollback.sh <previous-sha>}"')
        ->and(scriptCode('deploy.sh'))->not->toContain('sudo');
});

it('smoke-checks through the same address users use, with an opt-in curl seam only for rehearsals', function () {
    $code = scriptCode('deploy.sh');

    expect($code)->toContain('https://${APP_HOST}/up')
        ->and($code)->toContain('${EASYPOST_CURL_OPTS:-}');
});

it('rolls back the image only, restarts nginx after php, and refuses when there is nothing to go back to', function () {
    $code = scriptCode('rollback.sh');

    expect($code)->toContain('[ "$TAG" = none ]')
        ->and($code)->toContain('exit 1')
        ->and($code)->toContain('dc restart nginx')
        ->and(at($code, 'dc up -d'))->toBeLessThan(at($code, 'dc restart nginx'))
        ->and($code)->not->toContain('migrate');
});

it('backs up a database dump that has been proven readable before it is trusted', function () {
    $code = scriptCode('backup.sh');

    expect($code)->toContain('pg_dump')
        ->and($code)->toContain('-Fc')
        // dumped to a temporary name: a failed pg_dump leaves an empty file after the
        // redirect, and `pipefail` does not cover a redirect
        ->and($code)->toContain('db.dump.tmp')
        ->and(at($code, 'test -s'))->toBeLessThan(at($code, 'pg_restore --list'))
        ->and(at($code, 'pg_restore --list'))->toBeLessThan(at($code, 'mv "$STAGE/db.dump.tmp"'))
        ->and(at($code, 'mv "$STAGE/db.dump.tmp"'))->toBeLessThan(at($code, 'restic backup'));
});

it('keeps seven daily and four weekly restore points, and checks the repository', function () {
    $code = scriptCode('backup.sh');

    expect($code)->toContain('restic forget')
        ->and($code)->toContain('--keep-daily 7')
        ->and($code)->toContain('--keep-weekly 4')
        ->and($code)->toContain('restic check');
});

it('gives mc a writable home when it runs as the invoking user', function () {
    // Found by the first rehearsal: `docker run --user <uid>` leaves HOME at `/`,
    // and mc then dies with "Unable to save new mc config. /.mc ... permission
    // denied". That aborted the backup, and with it the deploy that calls it.
    expect(scriptCode('backup.sh'))->toMatch('/-e\s+HOME=\/tmp\b|MC_CONFIG_DIR=/');
});

it('mirrors media as the invoking user, on the network MinIO is actually on', function () {
    $code = scriptCode('backup.sh');

    expect($code)->toContain('--user "$(id -u):$(id -g)"')
        ->and($code)->toContain('docker inspect')
        ->and($code)->toContain('--entrypoint mc')
        ->and($code)->toContain('mirror --overwrite --remove local/media');
});
