<?php

namespace App\Jobs;

use App\Models\PostGroup;
use App\Services\Posts\Exceptions\PublishRetryNeededException;
use App\Services\Posts\PublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Publishes one `PostGroup` to every channel it was scheduled to.
 * Dispatched by `PostGroupService` with `->delay($group->publish_date)`
 * whenever a group enters `QUEUE` (create, update, or a date change) —
 * see that class.
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
 * `release()` call, since only a queued job instance can do that, plus
 * recording the "retries exhausted" outcome via `failed()` below.
 *
 * `final`: this class is partial-mocked by nothing (see
 * `PublishPostJobTest`, which drives `release()`/`attempts()` via the
 * framework's own `InteractsWithQueue::withFakeQueueInteractions()` +
 * `assertReleased()` instead of `Mockery::mock(...)->makePartial()`) —
 * an earlier revision dropped `final` to accommodate a Mockery partial
 * mock; that was unnecessary and is reverted.
 *
 * ShouldBeUnique, keyed on the group id ($uniqueId()): the primary
 * defence against two instances of this job running the SAME group
 * concurrently — stronger than relying solely on `PublishService`'s own
 * per-channel row locks (see that class's docblock, idempotency point
 * 4), the same reasoning `RefreshIntegrationTokenJob` documents for
 * `Integration`. $tries/$backoff/$uniqueFor below are the literal values
 * this task's brief specifies (reproduced verbatim, not derived, unlike
 * `RefreshIntegrationTokenJob`'s schedule-derived numbers) — 4 attempts,
 * gaps of 1m/5m/15m between them, and a 3600s (1h) unique-lock TTL.
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
 *    data loss here. This job therefore always calls `release()` on
 *    `PublishRetryNeededException`, on every attempt including the last.
 *    Laravel's own `Worker` — not this class — is what stops the retry
 *    loop: the moment a redelivered attempt is popped with
 *    `attempts() > $tries`, the worker marks the job FAILED without
 *    calling `handle()` again, which DOES write to `failed_jobs`, DOES
 *    fire `JobFailed`, and DOES call `failed()` below with a
 *    `MaxAttemptsExceededException` — that is the loud, RECORDED failure
 *    this job relies on for the "retries exhausted" case. Post-review
 *    correction: an earlier revision stopped at "loud" (failed_jobs +
 *    JobFailed) and treated that as sufficient, reasoning that
 *    reconciling the group's own state afterwards was Task 32's job.
 *    Review finding 3 was that this is warning 4's silent failure
 *    relocated, not removed: `failed_jobs` is loud to an operator
 *    watching Horizon, but the PRODUCT stays silent — the group and its
 *    posts sit at `QUEUE` forever, `posts.error` stays null, and the
 *    API/calendar render that identically to "still scheduled, nothing
 *    wrong yet". `failed()` now closes that gap directly, inside this
 *    task's own contract (brief point 9: the group must end `PUBLISHED`
 *    or `ERROR`) — see that method below.
 *  - The `ProviderRateLimitException`-driven delay (via
 *    `PublishRetryNeededException::$retryAfterSeconds`) IS capped, same
 *    as `RefreshIntegrationTokenJob`. Post-review correction: an earlier
 *    revision argued this needed no cap specifically BECAUSE `$uniqueFor`
 *    is a fixed 3600s rather than schedule-derived — that reasoning was
 *    inverted. Fixed is exactly the property an unbounded provider delay
 *    can outlive: Telegram's own `retry_after` routinely exceeds an hour
 *    under a broadcast-wide rate limit, and once the unique lock expires
 *    a redispatch (e.g. a reschedule via `PostGroupService::updateDate()`)
 *    can enqueue a second job racing a first one still mid-retry — the
 *    exact precondition review finding 2 exploited. `nextBackoffDelay()`
 *    below is now the ceiling for BOTH the no-specific-delay case AND a
 *    rate limit's requested delay — see `handle()`.
 */
final class PublishPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    /**
     * @param  int  $queueVersion  Task 31: the `post_groups.queue_version`
     *                             this job was dispatched for — see
     *                             `PostGroupService::dispatchPublishIfQueued()`
     *                             and `PublishService::lockGroupIfQueued()`'s
     *                             docblocks. Always a real value from
     *                             production dispatch; every caller must
     *                             supply the group's value AT DISPATCH TIME,
     *                             never a value computed or re-read later.
     */
    public function __construct(
        public readonly string $postGroupId,
        public readonly int $queueVersion,
    ) {
        // Task 34: routes every dispatch to the `publish` queue without
        // touching any call site (`PostGroupService::dispatchPublishIfQueued()`,
        // `MissedPostsService::redispatchIfOrphaned()`) — neither sets
        // `->onQueue()`, so this assignment is the only place that
        // decides. Assigned in the constructor, not as a property
        // default: `Queueable::$queue` is already declared (untyped, no
        // default) by the trait this class uses, and PHP treats a
        // class-level property redeclaration with a *different* default
        // as an incompatible trait composition (a hard fatal error at
        // class-load time, verified directly) — not merely an
        // "override", despite that being allowed for e.g. visibility.
        // See `config/horizon.php` for the ten dedicated workers behind
        // this queue name.
        $this->queue = 'publish';
    }

    public function uniqueId(): string
    {
        return $this->postGroupId;
    }

    /**
     * Task 34: Horizon tags, so a failed publish is findable in the UI
     * by the group or the organization it belongs to. This job carries
     * only `$postGroupId` (a bare string — see the class docblock's
     * reasoning for why it is deliberately thin), so the organization id
     * is not already in memory the way it is for
     * `RefreshIntegrationTokenJob`'s `Integration`; deriving it costs one
     * extra query, run once per dispatch (Horizon computes tags when the
     * job is pushed onto the queue, not on every worker attempt — see
     * `Laravel\Horizon\JobPayload::prepare()`), a single indexed lookup
     * by primary key on `post_groups`. `withoutGlobalScopes()` — not a
     * plain `PostGroup::find()` — for the same reason every other
     * no-`OrganizationContext` read in this codebase uses it (see
     * `BelongsToOrganization`'s docblock): a job dispatch has no tenant
     * context set, and the scope fails closed to zero rows without it.
     * Tags carry ids only, never names, emails, or tokens — see
     * `config/horizon.php`'s docblock and Task 14's token-leak finding
     * this rule exists to not repeat, since tags render in a web UI.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        /** @var string|null $organizationId */
        $organizationId = PostGroup::withoutGlobalScopes()
            ->whereKey($this->postGroupId)
            ->value('organization_id');

        return array_values(array_filter([
            $organizationId !== null ? "organization:{$organizationId}" : null,
            "group:{$this->postGroupId}",
        ]));
    }

    public function handle(PublishService $service): void
    {
        try {
            $service->publishGroup($this->postGroupId, $this->queueVersion);
        } catch (PublishRetryNeededException $e) {
            // A rate limit's retryAfterSeconds is honoured but capped at
            // this attempt's own backoff-schedule position — never
            // trusted uncapped. See the class docblock's "Divergence"
            // section for why an uncapped provider-requested delay can
            // outlive $uniqueFor's fixed 3600s and reopen the exact
            // concurrent-run race review finding 2 fixed. A plain
            // transient/partial-failure retry (no specific delay
            // requested) uses the schedule position directly, unchanged.
            $ceiling = $this->nextBackoffDelay();
            $delay = $e->retryAfterSeconds === null ? $ceiling : min($e->retryAfterSeconds, $ceiling);

            $this->release($delay);
        }

        // Any other exception (an unclassified provider bug, a database
        // error, ...) deliberately propagates uncaught: Laravel's own
        // exception handling for a queued job applies this job's
        // $tries/$backoff automatically on an uncaught Throwable, then
        // writes to failed_jobs and fires JobFailed (and calls failed()
        // below) once exhausted — exactly the "fail loudly" this job
        // needs and RefreshIntegrationTokenJob's docblock calls out by
        // name. Nothing here should ever catch a provider exception type
        // other than PublishRetryNeededException — those are all already
        // handled inside PublishService, which never lets
        // ProviderPermanentException/ProviderAuthException reach this
        // far (both are terminal outcomes it resolves itself, per its
        // own docblock).
    }

    /**
     * Called by Laravel once this job is given up on for good — either
     * every attempt threw uncaught, or (the path this job is actually
     * expected to hit) `attempts() > $tries` was reached after a chain
     * of `release()` calls on `PublishRetryNeededException`, in which
     * case `$e` is a `MaxAttemptsExceededException` carrying no
     * sensitive detail (never a raw provider exception — a provider
     * failure that reaches this point already went through
     * `PublishService`, which resolves every provider exception type
     * itself and never lets one propagate this far).
     *
     * Delegates to `PublishService::abandonGroup()`: every post still
     * `QUEUE` in this group moves to `ERROR` with a message, and the
     * group is finalized. Review finding 3 — without this, a group that
     * exhausts retries is loud to `failed_jobs`/Horizon but silent to
     * the product: stuck at `QUEUE` forever, indistinguishable in the
     * API/calendar from "still scheduled, nothing wrong yet". This is
     * the fix, and it is squarely inside this task's own contract (the
     * brief's point 9 requires the group to end `PUBLISHED` or `ERROR`,
     * with no third option).
     *
     * Laravel calls `failed()` directly (`CallQueuedHandler::failed()`),
     * not through the container, so this cannot type-hint `PublishService`
     * as a parameter the way `handle()` does — resolved via `app()`
     * instead.
     */
    public function failed(?Throwable $e): void
    {
        $reason = $e !== null
            ? "Publishing failed after the maximum number of attempts: {$e->getMessage()}"
            : 'Publishing failed after the maximum number of attempts.';

        app(PublishService::class)->abandonGroup($this->postGroupId, $reason, $this->queueVersion);
    }

    /**
     * The delay for a retry that carries no provider-specified
     * `retryAfterSeconds` (a plain transient failure, or a partial
     * failure — see PublishRetryNeededException's docblock), AND the
     * ceiling a rate limit's requested delay is capped at — see
     * handle(). This attempt's own position in $backoff, or the
     * schedule's last entry if $backoff were ever shortened
     * independently of $tries.
     */
    private function nextBackoffDelay(): int
    {
        $index = $this->attempts() - 1;

        return $this->backoff[$index] ?? end($this->backoff);
    }
}
