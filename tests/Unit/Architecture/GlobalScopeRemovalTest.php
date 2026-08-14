<?php

use Symfony\Component\Finder\Finder;

/**
 * Removing the tenant scope must always name it.
 *
 * `BelongsToOrganization` fails closed — with no organization in context
 * its scope matches zero rows — so any code that legitimately runs
 * without a tenant (the publish job, the digest mailer, token refresh,
 * the share-link viewer) has to take the scope off to see anything at
 * all. That is expected and there are ~25 such sites. What matters is
 * WHICH scopes come off.
 *
 * `withoutGlobalScope('organization')` removes exactly the one the caller
 * reasoned about. `withoutGlobalScopes()` removes every scope the model
 * has now and every scope it is ever given later — so the day someone
 * adds `SoftDeletes` to `PostGroup`, that call silently starts returning
 * trashed rows too, in code written years earlier by someone who never
 * considered them. `ShareService` spends four paragraphs warning about
 * exactly this trap; nothing enforced it, and `PublishPostJob::tags()`
 * had walked into it.
 *
 * Final review, batch 4 (tenancy).
 */
it('never removes global scopes in bulk — the tenant scope is taken off by name', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $lines = explode("\n", (string) file_get_contents($file->getRealPath()));

        foreach ($lines as $number => $line) {
            // Prose about the rule is not a violation of it — this very
            // constraint is explained in half a dozen docblocks.
            if (preg_match('/^\s*(\*|\/\/)/', $line) === 1) {
                continue;
            }

            if (preg_match('/withoutGlobalScopes\s*\(/', $line) === 1) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * The by-name form is what the rule above asks for, so at least one
 * caller must be using it. Without this, deleting every scope-removal in
 * the codebase — or renaming the scope — would leave the test above
 * green while the constraint it describes had stopped applying to
 * anything.
 */
it('finds the by-name form actually in use', function () {
    $found = 0;

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $found += preg_match_all(
            "/withoutGlobalScope\s*\(\s*'organization'\s*\)/",
            (string) file_get_contents($file->getRealPath()),
        );
    }

    expect($found)->toBeGreaterThan(5);
});
