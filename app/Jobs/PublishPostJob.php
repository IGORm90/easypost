<?php

namespace App\Jobs;

use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Services\Posts\PublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Publishes one `PostGroup` to every channel it was scheduled to.
 * Dispatched by `PostGroupService` with `->delay($group->publish_date)`
 * whenever a group enters `QUEUE` (create or update) — see that class.
 *
 * Deliberately thin: carries only the group's id (a plain string, not
 * the model — `SerializesModels` would rehydrate it via
 * `newQueryForRestoration()`, which is scope-safe, but this job re-reads
 * the row itself anyway via `PublishService::publishGroup()`'s
 * `lockForUpdate()`, so there is nothing worth caching from dispatch
 * time; the group's state, its posts, even whether it still exists can
 * all have changed by the time this runs). ALL business logic — which
 * provider outcome does what — lives in `PublishService`; see that
 * class's docblock for the full idempotency accounting. This job's only
 * job is translating `PublishService`'s one signal that crosses the
 * service/queue boundary (`PublishRetryNeededException`) into a
 * `release()` call, since only a queued job instance can do that.
 *
 * ShouldBeUnique, keyed on the group id ($uniqueId()): the primary
 * defence against two instances of this job running the SAME group
 * concurrently — stronger than relying solely on `PublishService`'s own
 * per-channel row locks (see that class's docblock, idempotency point
 * 4), the same reasoning `RefreshIntegrationTokenJob` documents for
 * `Integration`. $tries/$backoff/$uniqueFor below are the literal values
 * this task's brief specifies (reproduced verbatim, not derived, unlike
 * `RefreshIntegrationTokenJob`'s schedule-derived numbers) — 4 attempts,
 * gaps of 1m/5m/15m between them (worst case 1260s), and a 3600s (1h)
 * unique-lock TTL that comfortably covers that worst case plus real
 * queue/HTTP latency for every attempt, so the lock cannot expire mid-
 * retry and let `ScheduleTokenRefresh`-style re-dispatch (nothing does
 * that for this job today, but the margin costs nothing) race a still-
 * retrying instance.
 *
 * Retry semantics under this project's queue driver (QUEUE_CONNECTION=redis):
 * `release()` DOES consume one of $tries — `LuaScripts::pop()` increments
 * the reservation's attempt count on every pop, redeliveries included —
 * see `RefreshIntegrationTokenJob`'s docblock for the full explanation
 * this class inherits unchanged.
 *
 * Divergence from `RefreshIntegrationTokenJob`'s pattern — deliberate,
 * per that class's own warning to this one:
 *
 *  - No final-attempt "just return" guard. `RefreshIntegrationTokenJob`
 *    skips its last release() because a plain `return` from handle() is
 *    read by `CallQueuedHandler::call()` as SUCCESSFUL completion — the
 *    unique lock released, the job deleted, nothing in `failed_jobs`, no
 *    `JobFailed` event. That is harmless for a token refresh with an
 *    hourly self-healing re-enumeration behind it; it would be silent
 *    data loss here — a real customer post left unpublished with no
 *    record anywhere that anything went wrong. This job therefore always
 *    calls `release()` on `PublishRetryNeededException`, on every
 *    attempt including the last. Laravel's own `Worker` — not this
 *    class — is what stops the retry loop: the moment a redelivered
 *    attempt is popped with `attempts() > $tries`, the worker marks the
 *    job FAILED without calling `handle()` again, which DOES write to
 *    `failed_jobs` and DOES fire `JobFailed`. That is the loud failure
 *    this job relies on for the "retries exhausted" case — see the
 *    class docblock's note that reconciling a group stuck at `QUEUE`
 *    after that point is Task 32's job (the missed-post scanner), not
 *    this one's.
 *  - No manual cap on a `ProviderRateLimitException`-driven delay.
 *    `RefreshIntegrationTokenJob` caps every rate-limit release at that
 *    attempt's own backoff-schedule entry because ITS uniqueFor() is
 *    derived from that same schedule and could otherwise be outlived.
 *    This job's `$uniqueFor` is a fixed 3600s regardless of what any
 *    provider asks for, so `PublishRetryNeededException::$retryAfterSeconds`
 *    (itself already the MINIMUM across every rate-limited channel this
 *    run — see `PublishService::publishGroup()`) is honoured as-is. A
 *    provider asking for longer than the remaining budget this job's
 *    $tries/$uniqueFor allow simply exhausts retries sooner — the same
 *    accepted trade-off `RefreshIntegrationTokenJob` documents, applied
 *    to fixed rather than derived numbers.
 */
class PublishPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $postGroupId) {}

    public function uniqueId(): string
    {
        return $this->postGroupId;
    }

    public function handle(PublishService $service): void
    {
        try {
            $service->publishGroup($this->postGroupId);
        } catch (PublishRetryNeededException $e) {
            $this->release($e->retryAfterSeconds ?? $this->nextBackoffDelay());
        }

        // Any other exception (an unclassified provider bug, a database
        // error, ...) deliberately propagates uncaught: Laravel's own
        // exception handling for a queued job applies this job's
        // $tries/$backoff automatically on an uncaught Throwable, then
        // writes to failed_jobs and fires JobFailed once exhausted —
        // exactly the "fail loudly" this job needs and RefreshIntegrationTokenJob's
        // docblock calls out by name. Nothing here should ever catch a
        // provider exception type other than PublishRetryNeededException
        // — those are all already handled inside PublishService, which
        // never lets ProviderPermanentException/ProviderAuthException
        // reach this far (both are terminal outcomes it resolves itself,
        // per its own docblock).
    }

    /**
     * The delay for a retry that carries no provider-specified
     * `retryAfterSeconds` (a plain transient failure, or a partial
     * failure — see PublishRetryNeededException's docblock): this
     * attempt's own position in $backoff, or the schedule's last entry
     * if $backoff were ever shortened independently of $tries.
     */
    private function nextBackoffDelay(): int
    {
        $index = $this->attempts() - 1;

        return $this->backoff[$index] ?? end($this->backoff);
    }
}
