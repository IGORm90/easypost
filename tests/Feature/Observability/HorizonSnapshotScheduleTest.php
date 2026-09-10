<?php

use Illuminate\Console\Scheduling\Schedule;

/*
 * Horizon already collects queue throughput and wait-time samples; it
 * simply never persists them without `horizon:snapshot` running on a
 * schedule (see LOG_PLAN.md Phase 0). This asserts the actual production
 * entry point — the registered schedule event — not just that the artisan
 * command exists, so a missing or mistyped registration in
 * routes/console.php fails this test rather than being silently absent
 * from every deployment.
 */
test('horizon:snapshot is scheduled to run every five minutes, without overlapping, on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'horizon:snapshot'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/5 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->onOneServer)->toBeTrue();
});
