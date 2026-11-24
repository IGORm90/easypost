<?php

use Symfony\Component\Yaml\Yaml;

/*
 * .github/workflows/ci.yml is the gate a release waits for (release.yml refuses a commit
 * without a green run), so a mistake in it blocks every deploy. It cannot be run from a
 * test; what can be pinned is the order of its steps.
 */

/** @return list<string> the `run:` command of every step, in order ('' for a `uses:` step) */
function ciRunCommands(): array
{
    /** @var array{jobs: array{ci: array{steps: list<array<string, mixed>>}}} $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

    return array_map(fn (array $step) => (string) ($step['run'] ?? ''), $workflow['jobs']['ci']['steps']);
}

function ciStepIndex(string $needle): int
{
    foreach (ciRunCommands() as $index => $command) {
        if (str_contains($command, $needle)) {
            return $index;
        }
    }

    throw new RuntimeException("no ci.yml step runs «{$needle}»");
}

it('builds the frontend before the backend suite, because public/build is not in the repository', function () {
    // Every test that renders resources/views/app.blade.php calls @vite, which throws
    // "Vite manifest not found" when public/build/manifest.json is missing. A developer
    // always has it from an earlier build; a fresh CI checkout never does, and 128 tests
    // failed on the first run for exactly this reason.
    expect(ciStepIndex('npm run build'))->toBeLessThan(ciStepIndex('./vendor/bin/pest'));
});
