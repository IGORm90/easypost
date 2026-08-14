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
 * Both stated limits of the grep layer are now fixed rather than merely
 * disclosed (final review, batch 4 — coverage and gates).
 *
 * The pattern used to be `['\"]{$p}['\"]`, which required the name to be
 * IMMEDIATELY quote-adjacent — so `config('services.telegram.bot_token')`
 * walked straight through it, and that is not prose about a provider,
 * it is a service reaching into one provider's credentials by name. The
 * pattern now matches a provider name appearing ANYWHERE INSIDE a quoted
 * string, which is what "hardcodes a provider identifier" actually means
 * in PHP: a dotted config key, an interpolated cache key, a route name.
 *
 * The exclusion used to `str_contains()` the whole grep line, so a
 * violation whose own text happened to mention `app/Social/Providers`
 * excluded itself. It now splits the `path:line:text` output and looks
 * only at the path.
 *
 * Still deliberately NOT matched: a bare `telegram` outside quotes.
 * Prose naming a network as an example of a generic mechanism is
 * documentation of provider-agnostic code, and banning it would get the
 * explanation deleted rather than the coupling removed — the same
 * reasoning resources/js/__tests__/providerIsolation.spec.ts applies on
 * the frontend.
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
/**
 * Splits one `path:line:text` grep result into [path, text].
 *
 * `explode(':', $line, 3)` rather than `strtok()`: the TEXT routinely
 * contains colons of its own (a URL, an array key, a time), and a
 * two-part split would hand back a truncated line as the "text" and hide
 * whatever came after the next colon.
 *
 * @return array{0: string, 1: string}
 */
function providerIsolationSplitGrepLine(string $line): array
{
    $parts = explode(':', $line, 3);

    return [$parts[0], $parts[2] ?? ''];
}

it('has no provider-specific branching outside app/Social/Providers', function () {
    $root = base_path('app');
    expect(is_dir($root))->toBeTrue("search root [{$root}] does not exist — the guard cannot have run");

    $hits = [];
    foreach (['telegram', 'mastodon', 'bluesky', 'discord', 'slack'] as $p) {
        $command = sprintf(
            'grep -rniE %s %s --include=%s',
            // The name anywhere inside a quoted string, not only when the
            // quotes touch it: catches 'telegram', "services.telegram.bot_token",
            // 'cache:telegram:app', and leaves unquoted prose alone.
            escapeshellarg("['\"][^'\"]*{$p}[^'\"]*['\"]"),
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
            function (string $line): bool {
                // grep emits `path:line:text`. Only the PATH decides
                // whether this is the allowed directory — testing the
                // whole line let a violation exclude itself by merely
                // mentioning that path in its own text.
                [$path, $text] = providerIsolationSplitGrepLine($line);

                if (str_contains($path, 'app/Social/Providers')) {
                    return false;
                }

                // Whole-line comments are never a violation, and must be
                // dropped BEFORE the quoted-string test: English prose
                // uses the apostrophe as a possessive ("Mastodon's URLs",
                // "discord.com's and slack.com's API legs"), so any
                // sentence naming two providers between two apostrophes
                // reads to a regex exactly like a quoted string with a
                // provider name in it. Widening the pattern is what made
                // this matter; it is prose, not coupling.
                return preg_match('#^\s*(?:\*|//|/\*|\#)#', $text) !== 1;
            },
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
