<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\Integrations\TokenRefreshService;
use App\Social\Exceptions\ProviderRateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes one Integration's OAuth token. Dispatched by
 * App\Console\Commands\ScheduleTokenRefresh, one job per due integration,
 * never for the whole batch at once — this is what lets ShouldBeUnique
 * (below) dedupe at the per-channel level.
 *
 * Carries the Integration model itself, not a bare id: SerializesModels
 * restores a queued model via Model::newQueryForRestoration(), which
 * explicitly bypasses ALL global scopes — including
 * BelongsToOrganization's 'organization' scope — so rehydrating this on
 * a worker with no OrganizationContext set (the normal state for a
 * queued job; see AppServiceProvider's queue-event listeners) does not
 * hit BelongsToOrganization's "fail closed to zero rows" behaviour the
 * way a query built through the model's normal scoped query builder
 * would. Verified directly, not assumed — see Model::newQueryForRestoration()
 * and Model::fresh(), both of which call newQueryWithoutScopes().
 *
 * Overlap/uniqueness decision: ShouldBeUnique, keyed on the integration's
 * id, for the whole time the job can plausibly be in flight
 * (uniqueFor()). Two RefreshIntegrationTokenJob instances for the SAME
 * integration racing would let the slower one overwrite the faster one's
 * freshly-stored token with an older value — a live channel would start
 * failing every subsequent publish with a token the provider has already
 * invalidated. ShouldBeUnique stops the second instance from ever being
 * pushed onto the queue while the first is still pending/executing,
 * which is a stronger guarantee than WithoutOverlapping middleware (that
 * would still let a second instance sit on the queue and run once the
 * first releases its lock). This is scoped per-integration, not
 * per-command: independent integrations must refresh concurrently, only
 * the same one must serialize. The scheduler command itself additionally
 * uses withoutOverlapping()+onOneServer() (see routes/console.php) so
 * the ENUMERATION step doesn't pile up if it ever gets slow — that's a
 * separate concern from this job's per-channel lock.
 *
 * Retry semantics under this project's queue driver (QUEUE_CONNECTION=redis)
 * -- corrected after review, since this docblock is the pattern Tasks
 * 30-33 are expected to copy: release() does NOT get a fresh attempt
 * budget. Illuminate\Queue\LuaScripts::pop() increments
 * reserved['attempts'] on every pop, including a redelivery after
 * release() (DatabaseQueue::markJobAsReserved() does the same for the
 * database driver) -- verified directly against the framework source,
 * not assumed. So a ProviderRateLimitException handled via release()
 * below DOES count against $tries exactly like an uncaught exception
 * would; enough consecutive rate-limited pops exhausts retries and the
 * job lands in failed_jobs, not a silent infinite wait.
 *
 * Deliberate decision for THIS job: that outcome is acceptable here,
 * which is why $tries below is a modest bounded number rather than
 * something crafted to survive an arbitrarily long rate-limit window.
 * Two things make it safe specifically for token refresh: (1)
 * dueForRefresh() fires 2 hours ahead of actual token expiry, and (2)
 * ScheduleTokenRefresh re-enumerates and dispatches a brand-new job
 * (fresh attempt counter) every hour regardless of whether a previous
 * job for the same integration succeeded, failed, or died into
 * failed_jobs. So even a worst-case "every attempt hits the rate limit"
 * run self-heals on the next hourly pass, well inside that 2-hour
 * runway -- a failed_jobs entry here costs nothing customer-visible.
 *
 * This reasoning does NOT transfer to Task 30's publish job. A queued
 * post has no such 2-hour safety net and no hourly re-enumeration
 * behind it -- exhausting $tries mid-rate-limit-window there means a
 * post that would have succeeded once the provider's own Retry-After
 * elapsed fails instead, for no reason the customer caused. That job
 * must NOT copy release($e->retryAfterSeconds) under the assumption
 * used in an earlier draft of this docblock (that it "doesn't consume
 * $tries" -- it does). It needs its own deliberate choice: e.g. a
 * $tries budget sized to the provider's realistic rate-limit window, an
 * explicit retryUntil() deadline (which Laravel checks INSTEAD of
 * $tries when defined -- see Worker::markJobAsFailedIfWillExceedMaxAttempts()),
 * or delete() immediately followed by a fresh, delayed dispatch() to
 * reset the attempt counter rather than releasing the same reservation.
 *
 * uniqueFor() MUST cover the whole worst-case retry lifetime -- second
 * correction after review. UniqueLock::acquire() creates the lock as
 * $cache->lock($key, $uniqueFor): a FIXED TTL set once at dispatch, never
 * refreshed across release()/retry cycles (CallQueuedHandler::failed()
 * only releases it when the job finishes for good). If the retry
 * schedule above can keep this job in flight longer than uniqueFor(),
 * the lock expires mid-retry, ScheduleTokenRefresh's next hourly pass
 * dispatches a SECOND concurrent job for the same integration, and the
 * two race to store a token -- exactly the failure ShouldBeUnique exists
 * to prevent (see this docblock's "Overlap/uniqueness decision" above).
 * An earlier revision widened $tries/backoff() to fix the retry-vs-$tries
 * problem above WITHOUT re-checking this bound, silently re-opening the
 * race: 8 tries x up to 7 backoff gaps sums to ~2.86h against a
 * hand-written uniqueFor() of 1h. Fixed by making uniqueFor() a pure
 * function of RETRY_BACKOFF_SECONDS/$tries (below) instead of an
 * independent constant, so the two cannot drift apart again -- widening
 * one automatically widens the other.
 *
 * Third correction -- capping every ProviderRateLimitException release()
 * at self::RETRY_BACKOFF_SECONDS's single MAXIMUM entry (an earlier
 * revision's fix) was itself still wrong: that cap applies PER RELEASE,
 * not once, so a job rate-limited on every attempt could release()
 * up to 7 times at ~3600s each -- as much as 25200s, versus the 10290s
 * array_sum() the whole uniqueFor() derivation rests on. Even three
 * consecutive rate-limited attempts (10800s) already exceed it. A
 * sustained provider rate-limit window or provider-side outage produces
 * exactly that pattern, so this was a real, not theoretical, way back
 * into the same race.
 *
 * Fixed by capping each release at the schedule value for the SPECIFIC
 * ATTEMPT it's on (self::RETRY_BACKOFF_SECONDS[$this->attempts() - 1]),
 * not the schedule's overall maximum -- see handle(). Attempts 1..7 (the
 * only attempts that can still lead to another try, since $tries = 8)
 * each release for at most their own position's schedule value, so the
 * cumulative worst case across every rate-limited attempt is exactly
 * array_sum(RETRY_BACKOFF_SECONDS) -- the identical sum maxInFlightSeconds()
 * is built from, not a second, independently-reasoned number. The final
 * attempt (8) does NOT release() at all if it's still rate-limited (see
 * handle()): releasing there would hold the lock for another wait that
 * can never lead to a further attempt anyway (Laravel marks the job
 * failed, releasing the unique lock, the moment it next sees
 * attempts() > $tries -- it never calls handle() again), so that wait
 * would only cost lock time for nothing.
 *
 * The trade this makes explicit: when a provider asks for a longer wait
 * than the schedule allows at the current position, THIS JOB retries
 * sooner than the provider asked and may simply hit the rate limit
 * again on the next attempt, consuming another one of $tries. That is
 * deliberate -- a lock whose duration is provably bounded is worth more
 * than perfectly honouring a delay this job cannot itself bound. Task 30
 * inherits the same trade-off if it copies this shape: a real customer
 * post may retry into the same rate limit more than once rather than
 * waiting out the provider's full requested window in one release().
 *
 * Third thing Task 30 must know before copying this shape: the
 * final-attempt guard above (return with no release()/fail()/delete())
 * is NOT a neutral no-op the way it might look. CallQueuedHandler::call()
 * treats a plain return from handle() as SUCCESSFUL completion -- the
 * unique lock is released, the job is deleted, and NOTHING is written to
 * failed_jobs; no JobFailed event fires either. So a job that dies this
 * way is invisible to standard Laravel failure monitoring -- it looks
 * exactly like every other successful run. For THIS job that is
 * harmless: ScheduleTokenRefresh re-enumerates and retries regardless
 * (see "Deliberate decision for THIS job" above), so nothing is lost by
 * not recording the attempt. A publish job must NOT copy this: returning
 * silently on exhaustion there would make an unpublished, unrecorded
 * post look like a success everywhere -- failed_jobs, alerting, the
 * user's own view of what happened. That job must fail loudly on its
 * final attempt (let the exception propagate, or call fail() explicitly)
 * rather than reuse this guard's plain return.
 */
class RefreshIntegrationTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The one retry schedule this whole class is built around: how long
     * to wait between attempts 1->2, 2->3, ... after an uncaught
     * ProviderTransientException (see backoff()), the ceiling a
     * ProviderRateLimitException's release() delay is capped at (see
     * handle()), and — via maxInFlightSeconds() — the basis for both
     * $tries and uniqueFor(). Never read $tries or uniqueFor() as
     * independent facts; both are derived from this array so they cannot
     * drift apart the way they did before this fix.
     *
     * @var list<int>
     */
    private const array RETRY_BACKOFF_SECONDS = [30, 60, 300, 900, 1800, 3600, 3600];

    /**
     * Per-attempt slack added on top of the raw backoff sum: actual
     * queue pickup latency and the provider HTTP call itself both take
     * real wall-clock time beyond the release delay alone, and
     * uniqueFor() must cover that too or a slow-but-legitimate attempt
     * could still outlive the lock.
     */
    private const int EXECUTION_MARGIN_SECONDS = 60;

    /**
     * One more than the number of backoff gaps: the first attempt plus
     * one retry per RETRY_BACKOFF_SECONDS entry. Set in the constructor
     * (a class constant can't call count()) rather than hand-written, so
     * this can never silently drift out of sync with the array it counts.
     */
    public int $tries;

    public function __construct(
        public readonly Integration $integration,
    ) {
        $this->tries = count(self::RETRY_BACKOFF_SECONDS) + 1;
    }

    public function uniqueId(): string
    {
        return $this->integration->id;
    }

    /**
     * Task 34: Horizon tags. Unlike `PublishPostJob`, no extra query —
     * this job carries the `Integration` model itself (see the class
     * docblock's "Carries the Integration model" paragraph), so
     * `organization_id` is already an in-memory attribute. Ids only,
     * never `$integration->name` or any token field — see
     * `config/horizon.php`'s docblock on why tags never carry anything
     * that could leak through the Horizon UI.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            "organization:{$this->integration->organization_id}",
            "integration:{$this->integration->id}",
        ];
    }

    /**
     * Derived from RETRY_BACKOFF_SECONDS (see maxInFlightSeconds()), NOT
     * a hand-written constant — see the class docblock's "uniqueFor()
     * MUST cover..." section for the race this guards against. A lock
     * shorter than the worst-case time this job can plausibly still be
     * retrying would let ScheduleTokenRefresh's next hourly pass dispatch
     * a second concurrent job for the same integration.
     */
    public function uniqueFor(): int
    {
        return self::maxInFlightSeconds();
    }

    /**
     * Governs the automatic retry delay after an uncaught
     * ProviderTransientException. A ProviderRateLimitException's delay
     * comes from the provider's own Retry-After via release() in
     * handle() instead, capped POSITIONALLY at this same array's entry
     * for the current attempt — see handle() and the class docblock's
     * "Third correction" section for why the cap must be per-position,
     * not this array's overall maximum.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::RETRY_BACKOFF_SECONDS;
    }

    /**
     * Worst-case wall-clock time this job can still legitimately be in
     * flight, attempt 1 through the last retry: every backoff gap at its
     * longest, plus one execution margin per attempt (including the
     * final one, which has no further gap after it but still needs time
     * to run — one more attempt than there are backoff gaps). This is
     * the single source uniqueFor() (and, indirectly, $tries) reads
     * from — see the class docblock. Static and parameterless
     * deliberately: it must depend only on RETRY_BACKOFF_SECONDS, never
     * on a per-instance value, so uniqueFor() (called by Laravel before
     * this job's constructor logic is relevant to it) and the
     * consistency test below both see the exact same number $tries was
     * derived from.
     */
    private static function maxInFlightSeconds(): int
    {
        $attempts = count(self::RETRY_BACKOFF_SECONDS) + 1;

        return array_sum(self::RETRY_BACKOFF_SECONDS)
            + ($attempts * self::EXECUTION_MARGIN_SECONDS);
    }

    public function handle(TokenRefreshService $service): void
    {
        // newQueryWithoutScopes() under the hood (see class docblock) —
        // safe with no OrganizationContext set. Re-read rather than
        // trust the (possibly stale, since dispatch) constructor copy:
        // the channel may have been disabled, reconnected, or
        // soft-deleted in the time this job sat on the queue.
        $integration = $this->integration->fresh();

        if ($integration === null || $integration->disabled || $integration->trashed()) {
            // Idempotent no-op: already broken and notified, already
            // fixed by a reconnect, or gone. Nothing for this dispatch
            // to do — in particular, never re-run TokenRefreshService::refresh()
            // against an already-disabled integration, which is what
            // would re-trigger IntegrationBrokenNotification.
            return;
        }

        try {
            $service->refresh($integration);
        } catch (ProviderRateLimitException $e) {
            // Position-aware cap -- see the class docblock's "Third
            // correction" section. Capping at the schedule's overall
            // MAXIMUM (an earlier revision) still let repeated
            // rate-limited attempts release() several times at ~3600s
            // each, summing well past uniqueFor(). Capping at THIS
            // attempt's own schedule entry instead means the cumulative
            // worst case across every rate-limited attempt can never
            // exceed array_sum(RETRY_BACKOFF_SECONDS) -- the exact sum
            // uniqueFor() is built from.
            $attempt = $this->attempts();

            if ($attempt >= $this->tries) {
                // Last attempt: no further try is possible (Laravel
                // marks the job failed -- releasing the unique lock --
                // the moment it next sees attempts() > $tries, without
                // calling handle() again). Releasing here would only
                // hold the lock for a wait that can never be used, for
                // no benefit -- give up quietly instead. Same lock/
                // self-heal outcome as any other exhausted-retries case
                // for this job (see "Deliberate decision for THIS job"
                // above) but NOT the same observability: this plain
                // return is read by CallQueuedHandler::call() as a
                // successful completion, not a failure -- nothing lands
                // in failed_jobs, no JobFailed fires. See the class
                // docblock's third Task-30 warning before copying this.
                return;
            }

            // $attempt is always within [1, count(RETRY_BACKOFF_SECONDS)]
            // here (the guard above rules out the one value, $tries,
            // that would push the index out of range), so the ?? branch
            // is unreachable under this class's current invariants --
            // kept only as a defensive fallback against a future edit
            // that changes $tries independently of the array's length.
            $cap = self::RETRY_BACKOFF_SECONDS[$attempt - 1] ?? max(self::RETRY_BACKOFF_SECONDS);

            // Waits as long as the provider asked, but never longer than
            // this attempt's own cap. When the provider's requested
            // Retry-After exceeds it, this job deliberately retries
            // SOONER than requested and may simply hit the rate limit
            // again next attempt, consuming another one of $tries -- see
            // the class docblock's "trade this makes explicit"
            // paragraph. This DOES still consume one of $tries on this
            // project's redis queue regardless (attempts increment on
            // every pop, release included) -- see the "Retry semantics"
            // section, and Task 30 must not copy this line under the
            // opposite assumption.
            $this->release(min($e->retryAfterSeconds, $cap));

            return;
        }

        // Any ProviderTransientException from $service->refresh()
        // deliberately propagates uncaught here: TokenRefreshService
        // makes no state change for it, so letting it surface as a
        // failed job attempt is exactly what triggers this job's own
        // $tries/backoff() retry — never ProviderAuthException, which
        // TokenRefreshService already handles internally (marks the
        // integration broken and notifies) and never rethrows.
    }
}
