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
 * Scoped to `app/Social` as a whole, not just `app/Social/Providers`:
 * post-review widening, because a provider helper one directory up (a
 * shared client, a registry, a DTO that fetches something) bypasses a
 * providers-only guard silently while making exactly the same
 * unbounded call.
 *
 * A rule about a string of text in a file, like the two arch tests
 * beside this one, so it is checked by grep rather than by an `arch()`
 * preset (which understands class/namespace structure, not call sites).
 * grep's exit code is inspected explicitly — 0 matched, 1 ran fine with
 * no match, >1 a real failure — so "the search itself broke" can never
 * pass as green.
 */
it('never calls the Http facade directly anywhere under app/Social', function () {
    $root = base_path('app/Social');
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
            "grep failed while scanning app/Social for Http:: (exit code {$exitCode}): "
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

/**
 * Final-review batch 2, Critical 2, made executable for FUTURE providers
 * too: no provider may follow, or quietly pass on, a redirect, and a new
 * adapter added later must not reopen the hole by omission -- the same
 * failure mode that made the timeout guard above necessary.
 *
 * The refusal lives in `ProviderCall` (proven behaviourally in
 * tests/Unit/Social/ProviderCallTest.php and ProviderRedirectTest.php).
 * This is the structural half: every provider must send its calls through
 * it, either by constructing it or by using a trait that does
 * (UsesMetaGraph builds it once for Facebook Page, Instagram and Threads).
 */
it('sends every provider call through ProviderCall', function () {
    $files = glob(base_path('app/Social/Providers').'/*Provider.php') ?: [];

    expect($files)->not->toBe([], 'no provider classes found — the guard cannot have run');

    $delegating = [];

    foreach (glob(base_path('app/Social/Providers').'/*.php') ?: [] as $candidate) {
        if (str_contains((string) file_get_contents($candidate), 'new ProviderCall(')
            && ! str_ends_with($candidate, 'Provider.php')
            && ! str_ends_with($candidate, 'ProviderCall.php')) {
            $delegating[] = basename($candidate, '.php');
        }
    }

    $missing = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        $delegates = array_filter(
            $delegating,
            fn (string $trait): bool => preg_match('/\b'.preg_quote($trait, '/').'\b/', $source) === 1,
        );

        if (! str_contains($source, 'new ProviderCall(') && $delegates === []) {
            $missing[] = basename($file);
        }
    }

    expect($missing)->toBe([], "these providers do not send their calls through ProviderCall, so nothing refuses a redirect for them:\n".implode("\n", $missing));
});

/**
 * A provider that delegates to `ProviderCall` is only safe while
 * `ProviderCall` itself refuses the redirect. Without this, deleting the
 * refusal there would leave every provider green and unguarded. Checks the
 * call in the pipeline, not just the method's existence; the behaviour is
 * proven in ProviderCallTest.
 */
it('keeps the redirect refusal inside ProviderCall itself', function () {
    $source = (string) file_get_contents(base_path('app/Social/Providers/ProviderCall.php'));

    expect($source)->toContain('function refuseRedirect(');
    expect($source)->toContain('$this->refuseRedirect(');
});

/**
 * One redirect refusal, in one place. A second copy is how the rule drifts
 * (a different message, a different range) while the guard above stays green.
 */
it('defines the redirect refusal nowhere but ProviderCall', function () {
    $definitions = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Social'))) as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'function refuseRedirect(')) {
            $definitions[] = basename($file->getPathname());
        }
    }

    expect($definitions)->toBe(['ProviderCall.php']);
});
