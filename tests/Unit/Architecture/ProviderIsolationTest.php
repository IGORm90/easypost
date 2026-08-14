<?php

/**
 * Enforces the project's hardest rule: no provider-specific logic
 * outside app/Social/Providers. If a network's name shows up anywhere
 * else in app/ (a controller branching on 'telegram', a service
 * special-casing 'slack', ...) this test goes red.
 *
 * Two layers, deliberately:
 *  - a grep-based name check, because it catches *any* mention of a
 *    provider name regardless of what kind of code it's in (string
 *    comparison, match arm, comment referencing real logic, etc.) —
 *    the kind of violation an arch() preset can't see since it only
 *    understands class/namespace structure, not string literals;
 *  - Pest's arch() presets, which catch a different class of leak:
 *    app/Social/Providers/* classes being *depended on* from outside
 *    app/Social (e.g. a controller type-hinting TelegramProvider
 *    directly instead of going through SocialProviderInterface /
 *    ProviderRegistry). Neither check subsumes the other.
 *
 * Two stated limits of the grep layer, corrected here rather than
 * overclaimed (final-review batch 2, minor finding). The pattern is
 * `['\"]{$p}['\"]`, so it only sees a provider name that is IMMEDIATELY
 * quote-wrapped — `'telegram'`, not `telegram` in prose or
 * `'tele'.'gram'` — and the `str_contains($line, 'app/Social/Providers')`
 * exclusion matches the WHOLE grep line, so a violation whose own text
 * happens to mention that path excludes itself. There are provider-name
 * mentions in `app/` today it does not see; all of them are comments
 * describing real behaviour, so the RULE is not violated — but this
 * guard's reach is narrower than "any mention of a network's name
 * anywhere else in app/", and reading it as that guarantee would be a
 * mistake.
 *
 * The grep check deliberately does NOT pipe grep's output through a
 * second grep with `|| true`: that pattern silently turns "the search
 * itself failed" (missing grep binary, bad path, permission error) into
 * the same empty result as "the search ran and found nothing" — an
 * always-green guard that never actually looked. Instead each search
 * runs once via exec(), its exit code is inspected explicitly (grep's
 * own contract: 0 = matched, 1 = ran fine but no match, >1 = a real
 * error), and provider-directory exclusion happens in PHP on the
 * captured output rather than via a second piped command.
 */
it('has no provider-specific branching outside app/Social/Providers', function () {
    $root = base_path('app');
    expect(is_dir($root))->toBeTrue("search root [{$root}] does not exist — the guard cannot have run");

    $hits = [];
    foreach (['telegram', 'mastodon', 'bluesky', 'discord', 'slack'] as $p) {
        $command = sprintf(
            'grep -rniE %s %s --include=%s',
            escapeshellarg("['\"]{$p}['\"]"),
            escapeshellarg($root),
            escapeshellarg('*.php'),
        );

        $outputLines = [];
        $exitCode = null;
        exec($command, $outputLines, $exitCode);

        // grep: 0 = match found, 1 = ran fine, no match, >1 = real error
        // (bad path, permission denied, binary missing, ...). Only the
        // last case is a guard failure distinct from "no violations".
        if ($exitCode > 1) {
            throw new RuntimeException(
                "grep failed while scanning for provider [{$p}] (exit code {$exitCode}): "
                .implode("\n", $outputLines)
            );
        }

        $violations = array_values(array_filter(
            $outputLines,
            fn (string $line): bool => ! str_contains($line, 'app/Social/Providers'),
        ));

        if ($violations !== []) {
            $hits[] = implode("\n", $violations);
        }
    }

    expect($hits)->toBe([]);
});

arch('nothing outside app/Social depends on a concrete provider implementation')
    ->expect('App\Social\Providers')
    ->not->toBeUsed()
    ->ignoring('App\Social\Providers');
