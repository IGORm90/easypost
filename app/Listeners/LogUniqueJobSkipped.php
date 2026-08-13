<?php

namespace App\Listeners;

use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Log;

/**
 * Logs every `ShouldBeUnique` dispatch Laravel silently drops because the
 * job's unique lock is still held by an earlier, still-in-flight (or
 * merely still-locked) instance — see
 * `Illuminate\Foundation\Bus\PendingDispatch::shouldDispatch()`, which
 * fires `UniqueJobSkipped` instead of throwing whenever
 * `UniqueLock::acquire()` returns false.
 *
 * Added directly because of `PublishPostJob`: its unique lock is a real
 * `Cache` lock acquired at dispatch time with a fixed TTL
 * (`$uniqueFor`), completely independent of `->delay()` — so a
 * multi-drag calendar rescheduling session can silently drop every
 * dispatch after the first for up to an hour. `PostGroupService`'s
 * reschedule paths (`update()`/`updateDate()`) now force-release that
 * lock before redispatching specifically to close that hole (see their
 * docblocks) — this listener is the safety net for every OTHER way a
 * unique dispatch could still be silently skipped, today or by a future
 * `ShouldBeUnique` job this project adds, so a swallowed dispatch is
 * never invisible again.
 *
 * Deliberately logs the job's class and (when available) its
 * `uniqueId()` — never the whole job object, which for `PublishPostJob`
 * is harmless (a bare id) but for a future `ShouldBeUnique` job could
 * carry anything, including credentials; see `Integration`'s docblock
 * for this project's standing rule against logging whole models/DTOs
 * that might carry sensitive fields.
 */
class LogUniqueJobSkipped
{
    public function handle(UniqueJobSkipped $event): void
    {
        $job = $event->job;

        Log::warning('unique_job.skipped', [
            'job' => $job::class,
            'unique_id' => method_exists($job, 'uniqueId') ? $job->uniqueId() : null,
        ]);
    }
}
