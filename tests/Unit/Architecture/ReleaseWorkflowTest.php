<?php

use Symfony\Component\Yaml\Yaml;

/*
 * .github/workflows/release.yml builds the images and ships a release to the
 * production host over SSH. It cannot be run from a test and a mistake in it is
 * found on the first real release, so what makes it safe is pinned from the file:
 * what may trigger it, what each job may touch, in what order a deploy checks
 * things, and that no credential or user input is ever pasted into a shell script.
 */

function workflowPath(string $name): string
{
    return dirname(__DIR__, 3).'/.github/workflows/'.$name;
}

/**
 * The workflow with its comment lines removed. The comments explain, among other
 * things, why `ssh-keyscan` or an upper-case image name are NOT used; a "must not
 * contain" check has to look at the code, not at the explanation of its absence.
 */
function releaseCode(): string
{
    $lines = array_filter(
        explode("\n", (string) file_get_contents(workflowPath('release.yml'))),
        fn (string $line) => ! preg_match('/^\s*#/', $line),
    );

    return implode("\n", $lines);
}

/** @return array<string, mixed> */
function releaseWorkflow(): array
{
    expect(workflowPath('release.yml'))->toBeFile();

    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(workflowPath('release.yml'));

    return $workflow;
}

/** @return array<string, mixed> */
function releaseJob(string $name): array
{
    /** @var array<string, array<string, mixed>> $jobs */
    $jobs = releaseWorkflow()['jobs'];

    return $jobs[$name];
}

/** @return list<array<string, mixed>> */
function releaseSteps(string $job): array
{
    /** @var list<array<string, mixed>> $steps */
    $steps = releaseJob($job)['steps'];

    return $steps;
}

/** @return list<string> */
function releaseStepNames(string $job): array
{
    return array_map(fn (array $step) => (string) ($step['name'] ?? ''), releaseSteps($job));
}

// ---------------------------------------------------------------- triggers

it('runs on a push to main and on a manual dispatch, and never on a pull request', function () {
    $workflow = releaseWorkflow();
    // `on` is a YAML 1.1 boolean to some parsers.
    $triggers = $workflow['on'] ?? $workflow[true];

    expect(array_keys($triggers))->toEqualCanonicalizing(['push', 'workflow_dispatch'])
        ->and($triggers['push']['branches'])->toBe(['main'])
        // A PR from a fork must not be able to reach the registry or the server.
        ->and($triggers)->not->toHaveKey('pull_request')
        ->and($triggers)->not->toHaveKey('pull_request_target')
        ->and($triggers['workflow_dispatch']['inputs']['sha']['required'])->toBeFalse();
});

it('builds on a push and deploys on a dispatch, never both from one event', function () {
    expect(releaseJob('images')['if'])->toBe("github.event_name == 'push'")
        ->and(releaseJob('deploy')['if'])->toBe("github.event_name == 'workflow_dispatch'")
        // A deploy must not start by building: it ships an image a green CI has already seen.
        ->and(releaseJob('deploy'))->not->toHaveKey('needs');
});

// ------------------------------------------------------------- permissions

it('grants nothing by default and only what each job needs', function () {
    expect(releaseWorkflow()['permissions'])->toBe(['contents' => 'read'])
        ->and(releaseJob('images')['permissions'])->toBe(['contents' => 'read', 'packages' => 'write'])
        // The deploy job reads CI results and lends a short-lived read token to the
        // server's `docker pull`; it cannot publish a package.
        ->and(releaseJob('deploy')['permissions'])->toBe(['contents' => 'read', 'actions' => 'read', 'packages' => 'read']);
});

it('pins every action to a major version, never a moving branch', function () {
    foreach (['images', 'deploy'] as $job) {
        foreach (releaseSteps($job) as $step) {
            if (isset($step['uses'])) {
                expect((string) $step['uses'])->toMatch('/@v\d+$/');
            }
        }
    }
});

it('bounds both jobs in time', function (string $job) {
    expect(releaseJob($job)['timeout-minutes'])->toBeInt()->toBeLessThanOrEqual(60);
})->with(['images', 'deploy']);

// ------------------------------------------------------------------ images

it('builds the app and web images for the host architecture from one Dockerfile', function () {
    $builds = array_values(array_filter(
        releaseSteps('images'),
        fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'docker/build-push-action@'),
    ));

    expect($builds)->toHaveCount(2)
        ->and(array_map(fn (array $b) => $b['with']['target'], $builds))->toBe(['app', 'web']);

    foreach ($builds as $build) {
        expect($build['with']['file'])->toBe('docker/production/Dockerfile')
            ->and($build['with']['push'])->toBeTrue()
            // A Mac builds arm64; the VPS is amd64. The runner is the only place that builds the right one.
            ->and($build['with']['platforms'])->toBe('linux/amd64')
            // Tagged by commit, immutable: a rollback is a change of tag.
            ->and((string) $build['with']['tags'])->toContain('${{ github.sha }}')
            // Links the package to this repository, which is what lets the workflow's own token read it.
            ->and((string) $build['with']['labels'])->toContain('org.opencontainers.image.source');
    }

    expect((string) $builds[0]['with']['tags'])->not->toContain('-web:')
        ->and((string) $builds[1]['with']['tags'])->toContain('-web:');
});

it('lowercases the image name, because GHCR refuses upper case and the owner has some', function () {
    // github.repository is "IGORm90/easypost". `ghcr.io/IGORm90/easypost` is an invalid reference.
    $text = releaseCode();

    expect($text)->toContain('${GITHUB_REPOSITORY,,}')
        ->and($text)->not->toContain('ghcr.io/${{ github.repository }}');
});

// ------------------------------------------------------------------ deploy

it('guards a deploy in this order: valid sha, on main, green CI, then touches the server', function () {
    $names = releaseStepNames('deploy');

    $order = ['Validate the SHA', 'Check out', 'Require the SHA to be on main', 'Require a green CI', 'Set up SSH', 'Log the server in', 'Ship', 'Deploy'];
    $positions = [];

    foreach ($order as $prefix) {
        $found = array_keys(array_filter($names, fn (string $name) => str_starts_with($name, $prefix)));
        expect($found)->not->toBeEmpty("no deploy step named «{$prefix}…»");
        $positions[] = $found[0];
    }

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted, 'deploy steps out of order: '.implode(' < ', $order));
});

it('asks for approval and never runs two deploys at once', function () {
    $deploy = releaseJob('deploy');

    expect($deploy['environment'])->toBe('production')
        ->and($deploy['concurrency']['group'])->toBe('production-deploy')
        // Cancelling a deploy half way through a migration is how a database is left between versions.
        ->and($deploy['concurrency']['cancel-in-progress'])->toBeFalse();
});

it('accepts only a full 40-character commit SHA', function () {
    expect(releaseCode())->toContain('^[0-9a-f]{40}$');
});

it('deploys only a commit that is on main and that the CI workflow passed', function () {
    $text = releaseCode();

    expect($text)->toContain('git merge-base --is-ancestor')
        ->and($text)->toContain('origin/main')
        ->and($text)->toContain('gh run list --workflow ci.yml')
        ->and($text)->toContain('--status success');

    // ...and that workflow exists and runs for pushes to main, otherwise the check would wait for ever.
    $ci = Yaml::parseFile(workflowPath('ci.yml'));
    $triggers = $ci['on'] ?? $ci[true];

    expect($triggers['push']['branches'])->toBe(['main']);
});

it('never puts a secret or user input into a shell script, only into the environment', function () {
    foreach (['images', 'deploy'] as $job) {
        foreach (releaseSteps($job) as $step) {
            if (isset($step['run'])) {
                // `${{ }}` is expanded BEFORE the shell sees it: a secret would land in the script text
                // and a crafted `sha` input would be executed as code.
                expect((string) $step['run'])->not->toContain('${{', 'step «'.($step['name'] ?? '?').'» interpolates an expression into a script');
            }
        }
    }
});

it('verifies the server by its pinned host key and never prompts', function () {
    $text = releaseCode();

    // The host key comes from a secret that the operator recorded once, from the server itself.
    // Scanning for it at deploy time would trust whatever answers on that address that day.
    expect($text)->toMatch('/StrictHostKeyChecking[ =]yes/')
        ->and($text)->toMatch('/BatchMode[ =]yes/')
        ->and($text)->toContain('DEPLOY_KNOWN_HOSTS')
        ->and($text)->not->toMatch('/StrictHostKeyChecking[ =]no/')
        ->and($text)->not->toContain('ssh-keyscan');
});

it('lends the server a short-lived registry token instead of leaving a long-lived one on it', function () {
    $text = releaseCode();

    expect($text)->toContain('docker login ghcr.io')
        ->and($text)->toContain('--password-stdin');
});

it('ships exactly the host-mounted files the production stack reads, taken from the same commit', function () {
    $compose = Yaml::parseFile(dirname(__DIR__, 3).'/docker/compose.production.yaml');

    // Every directory the stack bind-mounts from docker/ must reach the server, or a deploy
    // starts the new image on the old configuration.
    $mounted = [];
    foreach ($compose['services'] as $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            $source = explode(':', (string) $volume)[0];
            if (str_starts_with($source, './')) {
                $mounted[] = 'docker/'.substr($source, 2);
            }
        }
    }

    $rsync = collect(releaseSteps('deploy'))->first(fn (array $step) => str_contains((string) ($step['run'] ?? ''), 'rsync'));
    expect($rsync)->not->toBeNull();

    $script = (string) $rsync['run'];

    foreach (array_unique([...$mounted, 'docker/compose.production.yaml', 'scripts']) as $path) {
        expect($script)->toContain($path)
            ->and(dirname(__DIR__, 3).'/'.$path)->toBeReadableAny();
    }

    // The checkout is of the commit being deployed, not of whatever main is now.
    $checkout = collect(releaseSteps('deploy'))->first(fn (array $step) => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@'));
    expect($checkout['with']['ref'])->toBe('${{ env.SHA }}')
        ->and($checkout['with']['fetch-depth'])->toBe(0);
});

expect()->extend('toBeReadableAny', function () {
    expect(file_exists($this->value))->toBeTrue("{$this->value} does not exist");

    return $this;
});
