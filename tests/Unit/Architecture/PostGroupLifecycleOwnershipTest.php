<?php

use Symfony\Component\Finder\Finder;

/**
 * Only `PostGroupLifecycle` writes the columns that make a publish safe.
 *
 * `queue_version` is what lets a job notice it is stale, and
 * `last_dispatched_at` / `dispatch_attempts` are what the stuck-row scan and the
 * attempt cap run on. Their rules (a bump is exactly 1, a draft bumps nothing,
 * a redispatch does not clear `notified_at`, ...) used to be re-implemented at
 * every call site, and a call site that got one wrong double-published or lost a
 * post silently. They now live in one module; this keeps them there.
 *
 * Reading these columns is fine and common (the stale check, the scan, the log
 * lines). WRITING them anywhere else is a bug waiting for a race to find it.
 *
 * Exempt, on purpose: the model that declares the columns, and
 * `BackfillDurableSchedulingCommand` (a one-off historical backfill, decided to
 * stay outside the lifecycle). Migrations and factories live outside `app/`.
 *
 * A text check, like its neighbours, because `arch()` understands namespaces,
 * not assignments.
 */
it('writes queue_version, last_dispatched_at and dispatch_attempts only inside PostGroupLifecycle', function () {
    $columns = 'queue_version|last_dispatched_at|dispatch_attempts';
    $exempt = [
        'app/Services/Posts/PostGroupLifecycle.php',
        'app/Models/PostGroup.php',
        'app/Console/Commands/BackfillDurableSchedulingCommand.php',
    ];

    $patterns = [
        // $group->queue_version = 1;
        "/->({$columns})\\s*=(?![=>])/",
        // update(['queue_version' => ...]) / create([...]) / fill([...])
        "/['\"]({$columns})['\"]\\s*=>/",
        // raw SQL: SET queue_version = queue_version + 1
        "/\\b({$columns})\\s*=\\s*[^=\\s>]/",
    ];

    $offenders = [];

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relative, $exempt, true)) {
            continue;
        }

        $inLog = false;

        foreach (explode("\n", (string) file_get_contents($file->getRealPath())) as $number => $line) {
            $trimmed = ltrim($line);

            // The context array of a Log:: call names these columns to REPORT
            // them: `'queue_version' => $group->queue_version`. Not a write.
            if (preg_match('/\bLog::\w+\(/', $line) === 1) {
                $inLog = true;
            }
            $skipKeys = $inLog;
            if ($inLog && preg_match('/\]\)\s*;\s*$/', $line) === 1) {
                $inLog = false;
            }

            // A comment may talk about the columns freely.
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                continue;
            }

            foreach ($patterns as $index => $pattern) {
                // $patterns[1] is the array-key form; the others are never logging.
                if ($index === 1 && $skipKeys) {
                    continue;
                }

                if (preg_match($pattern, $line) === 1) {
                    $offenders[] = $relative.':'.($number + 1).': '.trim($line);

                    break;
                }
            }
        }
    }

    expect($offenders)->toBe([], "these write a lifecycle column outside PostGroupLifecycle:\n".implode("\n", $offenders));
});
