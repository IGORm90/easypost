<?php

use App\Jobs\ScanMissedPostsJob;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Horizon's job lists are the only history of what ran now that no log
 * aggregator is deployed, so success history must outlive "the last hour"
 * (the vendor default, 60 minutes, cannot answer "did yesterday's 09:00
 * posts go out"). Failures are kept a week, matching the failed_jobs prune
 * below, so the two views of a failure expire together.
 */
it('keeps completed jobs for 24 hours and failed ones for 7 days', function () {
    expect(config('horizon.trim'))->toMatchArray([
        'recent' => 1440,
        'completed' => 1440,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ]);
});

it('silences the high-frequency scan job in the completed list', function () {
    expect(config('horizon.silenced'))->toContain(ScanMissedPostsJob::class);
});

it('prunes failed_jobs older than 7 days once a day', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'queue:prune-failed'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--hours=168')
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->onOneServer)->toBeTrue();
});
