<?php

/**
 * Task 36's review rule, made executable: limit checks belong at exactly
 * two layers — `EnforcePlanLimit` (the route middleware, the fast
 * pre-check) and the Service layer that performs the real INSERT
 * (`IntegrationService::connect()`, `PostGroupService::create()`,
 * `MediaUploadService::persist()`, each via `PlanLimitService::withLimit()`).
 * Nowhere else.
 *
 * Widened in the final review, batch 4 (coverage and gates). It used to
 * scan `app/Http/Controllers` alone, while its own docblock claimed the
 * two-layer rule — so a limit check in a FormRequest, a JsonResource, a
 * second middleware or a route closure passed a guard that reads as
 * though it forbids them. Verified at the time: a FormRequest calling
 * `app(PlanLimitService::class)` was green.
 *
 * It now scans `app/` and `routes/` in full and works from an allow-list
 * of the places the rule actually permits, which is the same statement
 * the docblock makes, rather than a denial of one directory. Adding a
 * legitimate caller therefore means adding it here, deliberately —
 * which is the point: the rule is that the list is short.
 */
const PLAN_LIMIT_ALLOWED_PATHS = [
    // The service itself, and the tests/DTOs that live beside it.
    'app/Services/Billing/',
    // The three services that perform the guarded INSERTs.
    'app/Services/Integrations/IntegrationService.php',
    'app/Services/Posts/PostGroupService.php',
    'app/Services/Media/MediaUploadService.php',
    // The route middleware — the other half of the two-layer rule.
    'app/Http/Middleware/EnforcePlanLimit.php',
    // Reads usage; enforces nothing. The rule above exists so a limit is
    // never DECIDED outside the transaction that performs the INSERT — a
    // controller-level check() is a decision made where it cannot be made
    // atomically. `PlanLimitService::usage()` decides nothing and guards
    // nothing: it reports numbers the caller may already derive by
    // listing their own channels, posts and media. See PlanController.
    'app/Http/Controllers/Api/PlanController.php',
];

/**
 * The allow-list is a list of FILES, not of intentions, so it cannot tell
 * `usage()` from `withLimit()` — this does. An allow-listed file that
 * starts enforcing a limit is the exact regression the list would
 * otherwise wave through.
 */
it('never enforces a limit from an allow-listed reader', function () {
    $readers = ['app/Http/Controllers/Api/PlanController.php'];

    foreach ($readers as $reader) {
        $contents = (string) file_get_contents(base_path($reader));

        expect($contents)->not->toContain('withLimit(')
            ->and($contents)->not->toContain('->check(');
    }
});

it('calls PlanLimitService only from the two layers allowed to', function () {
    $roots = [base_path('app'), base_path('routes')];

    foreach ($roots as $root) {
        expect(is_dir($root))->toBeTrue("search root [{$root}] does not exist — the guard cannot have run");
    }

    $command = sprintf(
        'grep -rn %s %s --include=%s',
        escapeshellarg('PlanLimitService'),
        implode(' ', array_map('escapeshellarg', $roots)),
        escapeshellarg('*.php'),
    );

    $outputLines = [];
    $exitCode = null;
    exec($command, $outputLines, $exitCode);

    // grep's own contract: 0 = matched, 1 = ran fine but no match, >1 = a
    // real error (bad path, permission denied, missing binary). Inspected
    // explicitly rather than piped through `|| true`, which would turn
    // "the search itself failed" into the same empty result as "the
    // search found nothing" — an always-green guard that never looked.
    if ($exitCode > 1) {
        throw new RuntimeException(
            "grep failed while scanning for PlanLimitService (exit code {$exitCode}): "
            .implode("\n", $outputLines)
        );
    }

    $violations = array_values(array_filter($outputLines, function (string $line): bool {
        // Only the path decides; grep emits `path:line:text` and the text
        // of a legitimate mention often names another allowed file.
        $parts = explode(':', $line, 3);
        $path = $parts[0];
        $text = $parts[2] ?? '';

        foreach (PLAN_LIMIT_ALLOWED_PATHS as $allowed) {
            if (str_contains($path, $allowed)) {
                return false;
            }
        }

        // A whole-line comment naming the service is documentation of the
        // rule, not a call to it — `App\Enums\PlanLimit`'s docblock says
        // which service reads its cases, and should keep saying so.
        return preg_match('#^\s*(?:\*|//|/\*|\#)#', $text) !== 1;
    }));

    expect($violations)->toBe([]);
});

/**
 * A guard built on an allow-list goes silently green if the thing it
 * allows disappears — a renamed service, a moved namespace, a grep that
 * stops matching — and "no violations" then means "nothing was searched".
 */
it('still finds the legitimate callers it allows', function () {
    $command = sprintf(
        'grep -rln %s %s --include=%s',
        escapeshellarg('PlanLimitService'),
        escapeshellarg(base_path('app')),
        escapeshellarg('*.php'),
    );

    $files = [];
    exec($command, $files);

    expect(count($files))->toBeGreaterThan(3);
});
