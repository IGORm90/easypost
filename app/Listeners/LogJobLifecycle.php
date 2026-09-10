<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

/**
 * LOG_PLAN.md Phase 1.4: the source for requirement 4 (job RPS/duration).
 * Registered in AppServiceProvider::boot() against JobProcessing,
 * JobProcessed and JobFailed — events are `discover: false` (see
 * bootstrap/app.php), so nothing wires this automatically.
 *
 * $startedAt is `static`, not an instance property: Laravel resolves a
 * fresh listener instance per event dispatch (no container binding makes
 * it a singleton), so an instance property set in handleProcessing()
 * would already be gone by the time handleProcessed() runs on a
 * different instance. Keyed by job uuid() so overlapping jobs on the
 * same worker (queue:work --tries, or several sync dispatches back to
 * back) don't clobber each other's start time.
 *
 * Deliberately never logs the job payload — see PublishPostJob's
 * $postGroupId/$queueVersion-only shape and Integration's docblock on
 * why whole objects don't get logged; a future job could carry customer
 * data where this one carries only ids.
 */
class LogJobLifecycle
{
    /** @var array<string, float> */
    private static array $startedAt = [];

    public function handleProcessing(JobProcessing $event): void
    {
        self::$startedAt[$event->job->uuid()] = hrtime(true);
    }

    public function handleProcessed(JobProcessed $event): void
    {
        Log::channel('jobs')->info('job.processed', [
            'queue' => $event->job->getQueue(),
            'job' => $event->job->resolveName(),
            'attempts' => $event->job->attempts(),
            'duration_ms' => $this->takeDurationMs($event->job->uuid()),
            'result' => 'ok',
        ]);
    }

    public function handleFailed(JobFailed $event): void
    {
        Log::channel('jobs')->error('job.failed', [
            'queue' => $event->job->getQueue(),
            'job' => $event->job->resolveName(),
            'attempts' => $event->job->attempts(),
            'duration_ms' => $this->takeDurationMs($event->job->uuid()),
            'exception' => $event->exception->getMessage(),
            'result' => 'failed',
        ]);
    }

    private function takeDurationMs(string $uuid): int
    {
        $startedAt = self::$startedAt[$uuid] ?? null;
        unset(self::$startedAt[$uuid]);

        if ($startedAt === null) {
            return 0;
        }

        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
