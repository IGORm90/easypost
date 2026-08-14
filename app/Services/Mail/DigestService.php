<?php

namespace App\Services\Mail;

use App\Enums\PostState;
use App\Mail\WeeklyDigestMail;
use App\Models\PostGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Task 33: the weekly digest. `SendDigestEmailJob` (scheduled hourly, see
 * `routes/console.php`) does nothing but call `sendDueDigests()` — every
 * bit of cross-tenant reasoning lives here, matching `MissedPostsService`'s
 * split with `ScanMissedPostsJob`.
 *
 * ============================================================
 * "Monday 09:00 in the user's timezone" is 24+ different UTC instants
 * ============================================================
 *
 * There is no single UTC moment that is "Monday 09:00" for every user —
 * `users.timezone` is an IANA zone (validated `timezone:all`, see
 * `UpdateProfileRequest`), and this job runs on a fixed hourly UTC
 * schedule. `isDueNow()` below asks a much simpler, per-user question
 * instead: "is it currently Monday, local hour 9 or later, for THIS
 * user" — computed via `CarbonImmutable::now()->setTimezone($tz)`, i.e.
 * real IANA offset/DST-transition rules looked up from the system
 * tzdata for the zone in question, never a fixed offset or manual
 * arithmetic (contrast `resources/js/utils/timeSlots.ts`'s docblock,
 * which explains why ITS offset-diffing trick is safe only for a
 * recurring weekly slot with no absolute "when was this configured"
 * anchor — this code has no such excuse: PHP's tzdata-backed
 * `DateTimeZone` is available and correct, so it is used directly).
 *
 * `hour >= 9`, not `hour === 9` — post-review correction. A flat
 * `=== 9` predicate has exactly ONE hour of margin: if `sendDueDigests()`
 * ever drifts past a user's local 10:00 (a slow chunk, a delayed worker,
 * an outage) before reaching them, `isDueNow()` returns false for the
 * REST of that Monday, and the NEXT tick evaluates them at local hour
 * 10, which also fails — that user silently loses the entire week, with
 * nothing to notice or retry. Worse, `chunkById()` walks users in a
 * fixed ULID order, so it is the SAME tail of users penalised every
 * single week a sweep runs long. `hour >= 9` makes the predicate
 * self-correcting: any tick from local 09:00 through 23:59 on Monday
 * still matches, so a late-running sweep simply catches up on its next
 * tick instead of losing the user outright. This is safe to widen
 * precisely BECAUSE `claim()`'s six-day cooldown already makes a
 * duplicate send impossible regardless of how many hours a Monday
 * "still matches" for a given user — the cooldown does the exactness
 * work, so this predicate doesn't have to.
 *
 * Why hourly sampling is sufficient even for fractional-hour offsets
 * (e.g. Asia/Kolkata, UTC+5:30; Chatham Islands, UTC+12:45): the local
 * "hour 9 or later, still Monday" window spans from local 09:00:00
 * through local Monday's last instant — many contiguous hours, not one.
 * This job's own sample points (every UTC top-of-hour) are spaced
 * exactly 60 minutes apart, so at least one (typically several) hourly
 * ticks land inside every user's window every single Monday, independent
 * of whether their zone's UTC offset is a whole hour, a half hour
 * (Kolkata), 45 minutes (Chatham), or anything else tzdata defines
 * (verified directly against Kolkata/Eucla/Chatham offsets, not merely
 * assumed).
 *
 * DST transitions, reasoned through explicitly (this project has been
 * bitten twice by DST arithmetic already — Tasks 21 and 25):
 *
 *  - "Spring forward" lands local hour 9 mid-jump for a zone whose
 *    transition instant is itself 09:xx (rare — most real IANA zones
 *    transition around 01:00-03:00 local, never 09:00 — but not
 *    impossible by construction). Local hour 9 never occurs that Monday
 *    for that user, but with `hour >= 9` the NEXT local hour (10, or
 *    whichever the clock actually lands on) still matches — so unlike
 *    the old `=== 9` predicate, this is not even a skipped week anymore,
 *    just a slightly later send that same day.
 *  - "Fall back" can make local hour 9 occur TWICE in one Monday, at two
 *    UTC instants roughly an hour apart — and with `hour >= 9`, EVERY
 *    hourly tick for the rest of that Monday also matches. This is NOT
 *    handled by the scheduling predicate at all; it is handled entirely
 *    by `claim()`'s cooldown below, which does not care how many times
 *    `isDueNow()` says yes for the same user on the same calendar
 *    Monday. Belt-and-suspenders, same division of responsibility
 *    `MissedPostsService` uses between its lock probe (quality) and
 *    `queue_version`/row-lock (correctness).
 *
 * ============================================================
 * Runs with NO OrganizationContext set
 * ============================================================
 *
 * `SendDigestEmailJob` is a scheduled job — see `AppServiceProvider`'s
 * queue-event listeners, which clear `OrganizationContext` around every
 * job boundary. `User` itself carries no `organization_id` (it is not
 * `BelongsToOrganization` — a user can belong to several organizations),
 * so enumerating users needs no scope bypass. `PostGroup`, however, IS
 * `BelongsToOrganization`: every query against it in `buildDigest()` goes
 * through `withoutGlobalScope('organization')` BY NAME plus an explicit
 * `whereIn('organization_id', $organizationIds)` — never a blanket
 * `withoutGlobalScopes()` — restricted to precisely the organizations
 * `$user->organizations()` returns. This is the actual security boundary
 * this class exists to get right: a user in two organizations must see
 * both in ONE digest; a user must never see a third organization's rows,
 * however briefly (see `DigestTest`'s cross-organization coverage).
 *
 * ============================================================
 * One user's failure must not cost every user after them
 * ============================================================
 *
 * `sendDueDigests()` walks every due user serially, sending SMTP inline.
 * `sendIfDue()` wraps the actual send in a try/catch for two reasons:
 * (1) a bad recipient address or a transient SMTP error must not
 * propagate out of `chunkById()`'s callback and abort the whole sweep —
 * every user still queued behind the failing one would otherwise lose
 * that tick, and per the `hour >= 9` reasoning above, likely the whole
 * week too, purely because someone else's email bounced; (2) `claim()`
 * has already committed by the time `Mail::send()` runs, so a caught
 * exception explicitly RELEASES the claim (restores the pre-claim
 * `digest_sent_at`) rather than leaving it consumed — a failed send must
 * not burn that user's six-day cooldown, or they lose their week too,
 * just for a different reason. `sendDueDigests()`'s own loop ALSO
 * wraps each `sendIfDue()` call, purely as a second, outer safety net
 * against an exception from anywhere else in the per-user path (e.g.
 * `$user->organizations()` itself failing) that the inner try/catch
 * does not cover, for the same "one user cannot take the sweep down"
 * reason.
 */
class DigestService
{
    /**
     * Bounded batch size for the cross-tenant user sweep — this runs
     * unattended, every hour, forever; see `MissedPostsService::CHUNK_SIZE`
     * for the identical reasoning.
     */
    private const int CHUNK_SIZE = 100;

    /**
     * How many rows any one category ever lists in a single digest email —
     * see `DigestSection`'s docblock.
     */
    private const int LIST_LIMIT = 50;

    /**
     * The idempotency guard's cooldown window — see `claim()`'s docblock
     * for why 6 days (not 7) is the right bound.
     */
    private const int CLAIM_COOLDOWN_DAYS = 6;

    public function sendDueDigests(): void
    {
        User::where('email_notifications', true)
            ->chunkById(self::CHUNK_SIZE, function (Collection $users): void {
                foreach ($users as $user) {
                    try {
                        $this->sendIfDue($user);
                    } catch (Throwable $e) {
                        // Outer safety net — see the class docblock's "one
                        // user's failure must not cost every user after
                        // them". Nothing here has claimed anything if it
                        // reached this catch (sendIfDue()'s own inner
                        // try/catch already handles the post-claim case),
                        // so there is nothing to release; this only exists
                        // to guarantee the loop itself never aborts.
                        Log::error('digest.sweep_failed', [
                            'user_id' => $user->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    /**
     * `email_notifications` is re-checked here (not just in
     * `sendDueDigests()`'s query) purely as defence in depth against a
     * toggle landing mid-chunk — the query-level filter is what actually
     * matters day to day.
     */
    private function sendIfDue(User $user): void
    {
        if (! $user->email_notifications || ! $this->isDueNow($user)) {
            return;
        }

        /** @var SupportCollection<int, string> $organizationIds */
        $organizationIds = $user->organizations()->pluck('organizations.id');

        if ($organizationIds->isEmpty()) {
            // Nothing any digest for this user could possibly say — most
            // likely a user mid-onboarding with no organization yet.
            // Deliberately NOT claimed (see claim()'s docblock): there is
            // no "week" being consumed here, so a user who joins an
            // organization later the same day is not locked out of this
            // Monday's digest by an empty claim made before they joined.
            return;
        }

        $claim = $this->claim($user);

        if ($claim === null) {
            return;
        }

        try {
            $summary = $this->buildDigest($organizationIds, $claim->previousDigestSentAt);

            Mail::to($user->email)
                ->locale($user->locale)
                ->send(new WeeklyDigestMail($user->name, $user->timezone, $summary));
        } catch (Throwable $e) {
            // See the class docblock: a failed send must not burn this
            // user's six-day cooldown, or they silently lose the whole
            // week on top of not getting this send. Restoring the
            // PRE-claim value (not just nulling it) matters too: for a
            // user who has received digests before, this correctly makes
            // them eligible again on the very next tick rather than
            // resetting them to "never sent" and disturbing an unrelated
            // future window calculation.
            Log::error('digest.send_failed', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            $this->release($user, $claim->previousDigestSentAt);
        }
    }

    private function isDueNow(User $user): bool
    {
        $local = CarbonImmutable::now()->setTimezone($user->timezone);

        return $local->dayOfWeekIso === CarbonImmutable::MONDAY && $local->hour >= 9;
    }

    /**
     * The claim that makes "nobody gets two digests" true regardless of
     * WHY `sendIfDue()` was asked twice for the same user — an hourly
     * tick and a manually re-run sweep landing in the same hour, a queue
     * redelivery after a worker crash, or the DST "fall back"
     * repeated-hour-9 case this class's docblock accepts as normal.
     *
     * Two steps, deliberately: a plain read of the row's CURRENT
     * `digest_sent_at` first, to capture it as `DigestClaim::
     * $previousDigestSentAt` for `buildDigest()`'s content window (see
     * that method's docblock) — then the actual claim, a conditional
     * `UPDATE ... WHERE email_notifications = true AND (digest_sent_at
     * IS NULL OR digest_sent_at < now() - 6 days)`, gated on the affected
     * row count, not a read-then-`save()` for the WRITE itself. This is
     * still the same atomic shape `MissedPostsService::notifyOne()` uses,
     * with a read bolted on before it, not replaced by one: Postgres
     * re-evaluates the `UPDATE`'s `WHERE` clause against the row's
     * CURRENT value at the moment the write actually applies, so of two
     * concurrent callers racing on the same user, only the one still
     * eligible at that instant affects the row — the other affects zero
     * rows and returns null.
     *
     * The preceding read is safe to use as "the" previous value
     * precisely because of that same guarantee: IF this method's own
     * `UPDATE` affects exactly one row, no other write to this column
     * could have landed between this method's read and its own `UPDATE`
     * — the only other possible writer is a concurrent call to this
     * exact method for the exact same user, and for either such call to
     * reach `$affected === 1` its `WHERE` must have matched the row's
     * value at that moment, which (if a competing write had already
     * landed) would have been the COMPETITOR's freshly-written `now()` —
     * a value newer than `now() - 6 days`, so the `WHERE` would already
     * have excluded it and this call would have gotten `$affected === 0`
     * instead. Whichever call actually wins, its own preceding read is
     * proven accurate by that same fact (see `DigestTest`'s forked
     * concurrency case, which proves the underlying atomicity against
     * real concurrent Postgres connections, not merely sequential
     * calls).
     *
     * Why 6 days, not 7: this fires once per calendar week per user, so a
     * legitimate next send is always roughly 7 days after the last one —
     * but "roughly" is the operative word. A DST transition can shift the
     * absolute UTC gap between two consecutive "Monday 09:00-or-later
     * local" instants by up to an hour either side of exactly 7*24h (see
     * the class docblock's spring-forward/fall-back accounting). A bound
     * of exactly 7 days would therefore reject the legitimate next
     * Monday's send in a zone that just lost an hour, while a bound as
     * loose as "6 days" still comfortably rejects every same-week repeat
     * (a fall-back double-fire is roughly an hour apart, nowhere near 6
     * days) without ever being able to reject a genuine week-later send.
     */
    private function claim(User $user): ?DigestClaim
    {
        /** @var User|null $row */
        $row = User::whereKey($user->id)->first(['id', 'email_notifications', 'digest_sent_at']);

        if ($row === null || ! $row->email_notifications) {
            return null;
        }

        if ($row->digest_sent_at !== null && $row->digest_sent_at->isAfter(now()->subDays(self::CLAIM_COOLDOWN_DAYS))) {
            return null;
        }

        $affected = User::whereKey($user->id)
            ->where('email_notifications', true)
            ->where(function ($query): void {
                $query->whereNull('digest_sent_at')
                    ->orWhere('digest_sent_at', '<', now()->subDays(self::CLAIM_COOLDOWN_DAYS));
            })
            ->update(['digest_sent_at' => now()]);

        if ($affected !== 1) {
            return null;
        }

        return new DigestClaim($row->digest_sent_at);
    }

    /**
     * Restores `digest_sent_at` to its pre-claim value — see
     * `sendIfDue()`'s catch block. A direct, unconditional write, not
     * another conditional `UPDATE`: by the time this runs, THIS call is
     * the only process that could possibly have written this column
     * since its own successful `claim()` (a concurrent claim attempt in
     * that window would itself have gotten `$affected === 0` and
     * returned null well before ever reaching a send, per `claim()`'s
     * docblock), so there is no competing writer to lose a race against.
     */
    private function release(User $user, ?CarbonInterface $previousDigestSentAt): void
    {
        User::whereKey($user->id)->update(['digest_sent_at' => $previousDigestSentAt]);
    }

    /**
     * `$previousDigestSentAt` (from `DigestClaim`, i.e. the user's
     * `digest_sent_at` as it stood immediately before THIS claim) is what
     * the past-content window (`published`/`failed`) anchors on — NOT a
     * freshly recomputed `now()->subDays(7)`. Falls back to
     * `now()->subDays(7)` only when `$previousDigestSentAt` is null (this
     * user's first-ever digest, where there is no prior send to chain
     * from anyway).
     *
     * Post-review fix: consecutive sends are NOT exactly 7*24h apart.
     * `isDueNow()`'s window (see the class docblock) fires on real
     * wall-clock local hours, whose absolute UTC instants shift by up to
     * an hour across a DST transition — verified directly: Europe/Berlin
     * 2026-10-19 09:10 local -> 2026-10-26 09:10 local is 7 days + 1 HOUR
     * apart in UTC (the fall-back), while America/New_York's own
     * transition the following week is 7 days - 1 hour. A window computed
     * as `now()->subDays(7)` at SEND time — this class's original
     * implementation — silently drops exactly the content published in
     * that one-hour seam after a fall-back (it falls before both
     * windows), and silently DUPLICATES it across two digests after a
     * spring-forward (it falls inside both windows). This is the exact
     * class of bug that bit Tasks 21 and 25, one layer down: THIS class
     * avoided the scheduling-predicate version of it (see `isDueNow()`),
     * but had reintroduced an equivalent bug in the CONTENT window
     * instead. Anchoring on the real, stored `previousDigestSentAt`
     * instead of recomputing "7 days ago" makes consecutive windows
     * chain exactly — `[previous_send, this_send)` — with no dependency
     * on how many real hours happened to separate them (see
     * `DigestTest`'s DST content-window test, which walks a user across
     * the Berlin 2026-10-19 -> 2026-10-26 fall-back and asserts a group
     * published in that exact seam appears in exactly one digest).
     *
     * The forward-looking `scheduled` window (`$now` to `$now + 7 days`)
     * is NOT anchored the same way, deliberately: nothing in the future
     * can be silently "dropped" the way past content can — the worst
     * case from the same DST jitter is a post mentioned in two
     * consecutive digests near a boundary, which is cosmetic, not a
     * safety-relevant loss the way a silently-unreported failure would
     * be.
     *
     * @param  SupportCollection<int, string>  $organizationIds
     */
    private function buildDigest(SupportCollection $organizationIds, ?CarbonInterface $previousDigestSentAt): DigestSummary
    {
        $now = CarbonImmutable::now();
        $weekStart = $previousDigestSentAt !== null
            ? CarbonImmutable::instance($previousDigestSentAt)
            : $now->subDays(7);
        $upcomingEnd = $now->addDays(7);

        return new DigestSummary(
            published: $this->fetchSection($organizationIds, PostState::PUBLISHED, 'publish_date', $weekStart, $now),
            failed: $this->fetchSection($organizationIds, PostState::ERROR, 'notified_at', $weekStart, $now),
            scheduled: $this->fetchSection($organizationIds, PostState::QUEUE, 'publish_date', $now, $upcomingEnd),
        );
    }

    /**
     * `$dateColumn` differs by category, deliberately:
     *
     *  - `published`/`scheduled` use `publish_date` — the group's own
     *    schedule, which is exactly what a user thinks of as "when this
     *    post is/was due".
     *  - `failed` uses `notified_at` (when `MissedPostsService::
     *    notifyOne()` reported the failure by email), NOT `publish_date`.
     *    A group's `publish_date` can be arbitrarily old by the time it
     *    finally exhausts its retries and settles to ERROR — filtering by
     *    `publish_date` would either miss a failure the user was just
     *    told about last week (its original `publish_date` might be
     *    weeks in the past) or resurface one they were told about long
     *    ago. `notified_at` is also guaranteed non-null for every ERROR
     *    group this query can match, since `notifyOne()` sets `error` and
     *    `notified_at` together, atomically, on the same claimed row.
     *
     * @param  SupportCollection<int, string>  $organizationIds
     */
    private function fetchSection(
        SupportCollection $organizationIds,
        PostState $state,
        string $dateColumn,
        CarbonInterface $start,
        CarbonInterface $end,
    ): DigestSection {
        $query = PostGroup::withoutGlobalScope('organization')
            ->whereIn('organization_id', $organizationIds)
            ->where('state', $state->value)
            ->whereBetween($dateColumn, [$start, $end])
            ->with('organization');

        $total = (clone $query)->count();

        $items = $query->orderBy($dateColumn)->limit(self::LIST_LIMIT)->get();

        return new DigestSection($items, $total);
    }
}
