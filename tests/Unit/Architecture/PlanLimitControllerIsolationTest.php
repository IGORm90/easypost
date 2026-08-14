<?php

/**
 * Task 36's review rule, made executable: no controller may call
 * PlanLimitService directly. Limit checks belong at exactly two layers —
 * EnforcePlanLimit (the route middleware, the fast pre-check) and the
 * Service layer that performs the real INSERT (IntegrationService::
 * connect(), PostGroupService::create(), MediaUploadService::persist(),
 * each via PlanLimitService::withLimit()) — never in a Controller, which
 * per this codebase's Layers constraint (FormRequest -> Controller ->
 * Service -> Model) is orchestration only.
 *
 * Same grep-based approach as ProviderIsolationTest, for the same reason:
 * this catches a bare `PlanLimitService::class`/`app(PlanLimitService::class)`
 * mention regardless of what kind of code it's embedded in, which an
 * arch() preset (class/namespace structure only) cannot see as reliably
 * for a "must not appear in this string of text" rule. The exit-code
 * handling matches ProviderIsolationTest's docblock: grep's own contract
 * (0 = matched, 1 = ran fine/no match, >1 = a real error) is inspected
 * explicitly rather than piping through `|| true`, which would silently
 * turn "the search itself failed" into an always-green result.
 */
it('never calls PlanLimitService from a controller (the brief\'s own grep check)', function () {
    $root = base_path('app/Http/Controllers');
    expect(is_dir($root))->toBeTrue("search root [{$root}] does not exist — the guard cannot have run");

    $command = sprintf(
        'grep -rn %s %s --include=%s',
        escapeshellarg('PlanLimitService'),
        escapeshellarg($root),
        escapeshellarg('*.php'),
    );

    $outputLines = [];
    $exitCode = null;
    exec($command, $outputLines, $exitCode);

    if ($exitCode > 1) {
        throw new RuntimeException(
            "grep failed while scanning app/Http/Controllers for PlanLimitService (exit code {$exitCode}): "
            .implode("\n", $outputLines)
        );
    }

    expect($outputLines)->toBe([]);
});
