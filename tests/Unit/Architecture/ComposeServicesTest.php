<?php

use Symfony\Component\Yaml\Yaml;

/*
 * docker/compose.yaml is the only description of how this stack survives a
 * crash, and nothing runs it in CI, so every property below is asserted from
 * the file itself. Each one is a failure that is silent until it happens:
 *
 *  - no healthcheck: a wedged horizon or a scheduler loop that stopped is
 *    "Up" forever, and posts stop going out with no signal anywhere;
 *  - no mem_limit: one leaking container takes the host (and Postgres) down;
 *  - no restart policy: a crash is permanent until someone logs in;
 *  - an unrotated log: stdout grows until the disk is full.
 *
 * `minio-init` is a one-shot and `node` is the Vite dev server; neither is a
 * long-running production service, so they are exempt from the first three.
 */
const COMPOSE_EXEMPT = ['minio-init', 'node'];

/** @return array<string, array<string, mixed>> */
function composeServices(): array
{
    /** @var array{services: array<string, array<string, mixed>>} $compose */
    // __DIR__, not base_path(): Pest evaluates a `with()` closure before the
    // application boots, when the container has no basePath() yet.
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/compose.yaml');

    return $compose['services'];
}

/** @return array<string, array<string, mixed>> */
function longRunningServices(): array
{
    return array_diff_key(composeServices(), array_flip(COMPOSE_EXEMPT));
}

function composeMegabytes(string $size): int
{
    preg_match('/^(\d+)([mg])$/i', $size, $m) || throw new LogicException("Unparsed size {$size}");

    return (int) $m[1] * (strtolower($m[2]) === 'g' ? 1024 : 1);
}

it('gives every long-running service a healthcheck', function (string $name) {
    expect(longRunningServices()[$name])->toHaveKey('healthcheck.test');
})->with(fn () => array_keys(longRunningServices()));

it('caps the memory of every long-running service', function (string $name) {
    expect(longRunningServices()[$name])->toHaveKey('mem_limit');
})->with(fn () => array_keys(longRunningServices()));

it('restarts every long-running service unless it was stopped on purpose', function (string $name) {
    expect(longRunningServices()[$name]['restart'] ?? null)->toBe('unless-stopped');
})->with(fn () => array_keys(longRunningServices()));

it('rotates the container log of every service', function (string $name) {
    $logging = composeServices()[$name]['logging'] ?? [];

    expect($logging['driver'] ?? null)->toBe('json-file')
        ->and($logging['options']['max-size'] ?? null)->toBe('10m')
        ->and($logging['options']['max-file'] ?? null)->toBe('3');
})->with(fn () => array_keys(composeServices()));

it('starts the php services only once postgres and redis answer', function (string $name) {
    $depends = composeServices()[$name]['depends_on'] ?? [];

    expect($depends['postgres']['condition'] ?? null)->toBe('service_healthy')
        ->and($depends['redis']['condition'] ?? null)->toBe('service_healthy');
})->with(['php', 'horizon', 'scheduler', 'pulse']);

/*
 * `maxmemory` + `noeviction`: under pressure Redis refuses writes instead of
 * silently dropping queued jobs (allkeys-lru would evict them). The append-only
 * file is pointless without a volume — `docker compose up --force-recreate`
 * would discard the whole queue.
 */
it('runs redis so that queued jobs are never evicted and survive a recreate', function () {
    $redis = composeServices()['redis'];
    $command = is_array($redis['command'] ?? null) ? implode(' ', $redis['command']) : (string) ($redis['command'] ?? '');

    expect($command)->toContain('--maxmemory ')
        ->and($command)->toContain('--maxmemory-policy noeviction')
        ->and($command)->toContain('--appendonly yes')
        ->and(implode(' ', $redis['volumes'] ?? []))->toContain(':/data');
});

/*
 * On `docker compose stop`/deploy, Horizon finishes the jobs it is running
 * before it exits. If Docker kills it sooner, a publish is cut mid-flight —
 * the one outcome this product must never have is publishing twice, and an
 * interrupted publish is how that starts.
 */
it('gives horizon longer to stop than its slowest supervisor may run a job', function () {
    $grace = (int) rtrim((string) (composeServices()['horizon']['stop_grace_period'] ?? '0s'), 's');

    $slowest = max(array_map(
        fn (array $supervisor) => $supervisor['timeout'],
        config('horizon.environments.*'),
    ));

    expect($grace)->toBeGreaterThan($slowest);
});

/*
 * The limit was once copied from a plan sized for 6–7 workers; the stack runs
 * 15 and idles at ~730 MiB. A worker is restarted only when it passes
 * `horizon.memory_limit`, so every worker may legitimately reach it.
 */
it('sizes the horizon memory limit for every worker at its own ceiling', function () {
    $workers = array_sum(array_map(
        fn (array $supervisor) => $supervisor['maxProcesses'],
        config('horizon.environments.*'),
    ));

    $needed = $workers * (int) config('horizon.memory_limit');

    expect(composeMegabytes((string) composeServices()['horizon']['mem_limit']))->toBeGreaterThan($needed);
});

/*
 * `pgrep -f` matches against full command lines, and a Docker healthcheck is
 * run as `sh -c "<the test>"` — whose own command line contains the pattern.
 * An unescaped `pgrep -f pulse:check` therefore matches the healthcheck shell
 * itself and reports healthy forever, even with pulse:check dead.
 */
it('does not let the pulse healthcheck match its own command line', function () {
    $test = composeServices()['pulse']['healthcheck']['test'];
    $shell = is_array($test) ? (string) end($test) : (string) $test;

    expect(preg_match('/pgrep -f (\S+)/', $shell, $m))->toBe(1);

    // Shell quoting around the pattern is not part of the pattern.
    $pattern = trim($m[1], "'\"");
    expect(preg_match('/'.$pattern.'/', $shell))->toBe(0);
});

/*
 * php-fpm serves web requests as www-data. If horizon, the scheduler or pulse
 * run as root they can be the first to write the day's laravel.log, which is
 * then created root:root 0644 and every web request (including /pulse) dies
 * with "Permission denied" the moment it tries to log. Running every process
 * that shares the log volume as www-data keeps one owner for the file.
 */
it('runs every php worker as the php-fpm user so they share ownership of the logs', function (string $name) {
    expect(composeServices()[$name]['user'] ?? null)->toBe('www-data');
})->with(['horizon', 'scheduler', 'pulse']);
