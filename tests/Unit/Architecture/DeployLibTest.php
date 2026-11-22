<?php

use Illuminate\Support\Facades\Process;

/*
 * scripts/lib.sh is the one place the production `docker compose` command line
 * is defined; deploy.sh, rollback.sh, backup.sh and an operator's shell all
 * source it. It is also what makes EASYPOST_TAG survive past a single script:
 * Compose refuses to run without the tag (`${EASYPOST_TAG:?...}`), so a shell
 * that did not export it could not even run `dc ps`.
 *
 * These tests run the real file under bash, with `docker` replaced by a stub
 * that prints its arguments, so they prove what is actually executed.
 */

/** @return array{dir: string, run: Closure(string, array<string, string>=): array{string, int}} */
function libSandbox(?string $currentTag): array
{
    $dir = sys_get_temp_dir().'/easypost-lib-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', recursive: true);
    file_put_contents($dir.'/bin/docker', "#!/bin/sh\nprintf '%s\\n' \"\$*\"\n");
    chmod($dir.'/bin/docker', 0755);

    if ($currentTag !== null) {
        file_put_contents($dir.'/.current-tag', $currentTag."\n");
    }

    $lib = dirname(__DIR__, 3).'/scripts/lib.sh';

    $run = function (string $body, array $env = []) use ($dir, $lib): array {
        $result = Process::env(['PATH' => $dir.'/bin:'.getenv('PATH'), 'EASYPOST_DIR' => $dir] + $env)
            ->run(['bash', '-c', ". '{$lib}'\n{$body}"]);

        return [trim($result->output()), $result->exitCode() ?? 1];
    };

    return ['dir' => $dir, 'run' => $run];
}

it('exports the release tag from .current-tag, so a fresh shell can run compose at all', function () {
    ['run' => $run] = libSandbox('abc123');

    expect($run('echo "$EASYPOST_TAG"')[0])->toBe('abc123');
});

it('lets an explicit EASYPOST_TAG win over the file, which is how deploy.sh switches release', function () {
    ['run' => $run] = libSandbox('abc123');

    expect($run('echo "$EASYPOST_TAG"', ['EASYPOST_TAG' => 'def456'])[0])->toBe('def456');
});

it('leaves the tag empty rather than failing when no release has been recorded yet', function () {
    ['run' => $run] = libSandbox(null);

    [$output, $exit] = $run('echo "[$EASYPOST_TAG]"');

    expect($output)->toBe('[]')->and($exit)->toBe(0);
});

it('defines dc as the production compose project with the server env file', function () {
    ['dir' => $dir, 'run' => $run] = libSandbox('abc123');

    expect($run('dc ps')[0])->toBe(
        "compose -p easypost -f {$dir}/docker/compose.production.yaml --env-file {$dir}/.env ps",
    );
});

it('lets a rehearsal point the same commands at its own project and env file', function () {
    ['dir' => $dir, 'run' => $run] = libSandbox('abc123');

    $output = $run('dc up -d', ['EASYPOST_PROJECT' => 'easypost-rehearsal', 'EASYPOST_ENV_FILE' => $dir.'/.env.rehearsal'])[0];

    expect($output)->toBe("compose -p easypost-rehearsal -f {$dir}/docker/compose.production.yaml --env-file {$dir}/.env.rehearsal up -d");
});

it('passes every argument through untouched, including quoted ones', function () {
    ['run' => $run] = libSandbox('abc123');

    expect($run('dc exec -T php php artisan tinker --execute="echo 1 + 1;"')[0])->toEndWith('exec -T php php artisan tinker --execute=echo 1 + 1;');
});

it('exports the env file path, because compose reads it for env_file as well as for --env-file', function () {
    ['dir' => $dir, 'run' => $run] = libSandbox('abc123');

    // Compose interpolates `${EASYPOST_ENV_FILE:-../.env}` from the process
    // environment, so it has to be exported, not merely set.
    expect($run('env | grep ^EASYPOST_ENV_FILE=')[0])->toBe("EASYPOST_ENV_FILE={$dir}/.env");
});
