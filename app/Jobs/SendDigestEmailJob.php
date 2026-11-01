<?php

namespace App\Jobs;

use App\Services\Mail\DigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The scheduled entry point for Task 33's weekly digest — see
 * `routes/console.php` for its `->hourly()` registration and
 * `DigestService`'s docblock for every bit of "Monday 09:00 in the
 * user's timezone", cross-tenant, and idempotency reasoning, which all
 * lives there. Deliberately thin, matching `ScanMissedPostsJob`'s own
 * shape: this job is only the queue/schedule boundary.
 *
 * `ShouldBeUnique`, for the identical reason `ScanMissedPostsJob`
 * documents: `Schedule::job()` builds a `CallbackEvent` whose
 * `withoutOverlapping()` mutex only spans the PUSH onto the queue, not
 * this job's actual execution — so two scheduler ticks can each push
 * cleanly yet still end up running concurrently on two workers (a slow
 * first run still mid-`chunkById()` when the next tick's push lands).
 * This lock is a second, execution-level layer; `DigestService::claim()`'s
 * own conditional `UPDATE` is what actually makes any one user's send
 * exactly-once even if this lock is ever bypassed.
 *
 * `$uniqueFor` is comfortably under the hourly schedule interval (3600s),
 * so a normal run's lock is always gone well before the next tick's push.
 */
final class SendDigestEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3300;

    public function __construct()
    {
        // Named explicitly although `default` is also the fallback: a job
        // that merely falls through is indistinguishable from one whose
        // author forgot to choose, and a rename of the fallback queue would otherwise silently strand it.
        // JobQueuesTest fails for any queued class that leaves this unset.
        $this->queue = 'default';
    }

    public function uniqueId(): string
    {
        return 'send-digest-emails';
    }

    public function handle(DigestService $service): void
    {
        $service->sendDueDigests();
    }
}
