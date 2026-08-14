<?php

namespace App\Services\Mail;

use App\Enums\PostState;
use App\Mail\WeeklyDigestMail;
use App\Models\PostGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Mail;

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
 * instead: "is it currently the 09:00-09:59 local hour, on a Monday, for
 * THIS user" — computed via `CarbonImmutable::now()->setTimezone($tz)`,
 * i.e. real IANA offset/DST-transition rules looked up from the system
 * tzdata for the zone in question, never a fixed offset or manual
 * arithmetic (contrast `resources/js/utils/timeSlots.ts`'s docblock,
 * which explains why ITS offset-diffing trick is safe only for a
 * recurring weekly slot with no absolute "when was this configured"
 * anchor — this code has no such excuse: PHP's tzdata-backed
 * `DateTimeZone` is available and correct, so it is used directly).
 *
 * Why hourly sampling is sufficient even for fractional-hour offsets
 * (e.g. Asia/Kolkata, UTC+5:30): the local "hour 9" window is 09:00:00
 * through 09:59:59, a continuous 60-minute span. This job's own sample
 * points (every UTC top-of-hour) are also spaced exactly 60 minutes
 * apart. Two grids with the same 60-minute period, one continuous,
 * always intersect in exactly one point per period, regardless of the
 * phase offset between them — so exactly one hourly tick lands inside
 * every user's local hour-9 window every single day, independent of
 * whether their zone's UTC offset is a whole hour, a half hour, or
 * (Nepal) 45 minutes.
 *
 * DST transitions, reasoned through explicitly (this project has been
 * bitten twice by DST arithmetic already — Tasks 21 and 25):
 *
 *  - "Spring forward" lands local hour 9 mid-jump for a zone whose
 *    transition instant is itself 09:xx (rare — most real IANA zones
 *    transition around 01:00-03:00 local, never 09:00 — but not
 *    impossible by construction). Local hour 9 then never occurs that
 *    Monday for that user at all: `isDueNow()` never matches, that
 *    user's digest is skipped for the week. Accepted, not fixed further —
 *    a skipped digest is not a data-loss bug (the following week's
 *    digest still covers the trailing 7 days via `buildDigest()`'s own
 *    window, so nothing reported ever silently vanishes), and no
 *    alternative rule ("nearest available hour"?) is obviously more
 *    correct for a genuinely nonexistent wall-clock hour.
 *  - "Fall back" can make local hour 9 occur TWICE in one Monday, at two
 *    UTC instants roughly an hour apart — both real hourly ticks can
 *    then observe `isDueNow() === true` for the same user on the same
 *    calendar Monday. This is NOT handled by the scheduling logic at
 *    all; it is handled by `claim()`'s cooldown below, which is
 *    independent of why a second "due" observation happened (a fall-back
 *    repeat, a re-run scheduler tick, a redelivered job — see that
 *    method's docblock). Belt-and-suspenders, same division of
 *    responsibility `MissedPostsService` uses between its lock probe
 *    (quality) and `queue_version`/row-lock (correctness).
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
                    $this->sendIfDue($user);
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

        if (! $this->claim($user)) {
            return;
        }

        Mail::to($user->email)
            ->locale($user->locale)
            ->send(new WeeklyDigestMail($user->name, $user->timezone, $this->buildDigest($user, $organizationIds)));
    }

    private function isDueNow(User $user): bool
    {
        $local = CarbonImmutable::now()->setTimezone($user->timezone);

        return $local->dayOfWeekIso === CarbonImmutable::MONDAY && $local->hour === 9;
    }

    /**
     * The atomic claim that makes "nobody gets two digests" true
     * regardless of WHY `sendIfDue()` was asked twice for the same user —
     * an hourly tick and a manually re-run sweep landing in the same hour,
     * a queue redelivery after a worker crash, or the DST "fall back"
     * double-Monday-hour-9 case this class's docblock accepts as normal.
     *
     * A single conditional `UPDATE ... WHERE email_notifications = true
     * AND (digest_sent_at IS NULL OR digest_sent_at < now() - 6 days)`,
     * not a read-then-`save()` — identical shape to
     * `MissedPostsService::notifyOne()`'s claim, and for the same reason:
     * Postgres re-evaluates an `UPDATE`'s `WHERE` clause against each
     * row's CURRENT value at the moment the write actually applies, so of
     * two concurrent callers racing on the same user, only the one that
     * is still first to see the row eligible at that instant affects it;
     * the other affects zero rows and must send nothing (see
     * `DigestTest`'s forked concurrency case, which proves this against
     * real concurrent Postgres connections, not merely sequential calls).
     *
     * Why 6 days, not 7: this fires once per calendar week per user, so a
     * legitimate next send is always roughly 7 days after the last one —
     * but "roughly" is the operative word. A DST transition can shift the
     * absolute UTC gap between two consecutive "Monday 09:00 local"
     * instants by up to an hour either side of exactly 7*24h (see the
     * class docblock's spring-forward/fall-back accounting). A bound of
     * exactly 7 days would therefore reject the legitimate next Monday's
     * send in a zone that just lost an hour, while a bound as loose as "6
     * days" still comfortably rejects every same-week repeat (a fall-back
     * double-fire is roughly an hour apart, nowhere near 6 days) without
     * ever being able to reject a genuine week-later send.
     */
    private function claim(User $user): bool
    {
        $affected = User::whereKey($user->id)
            ->where('email_notifications', true)
            ->where(function ($query): void {
                $query->whereNull('digest_sent_at')
                    ->orWhere('digest_sent_at', '<', now()->subDays(self::CLAIM_COOLDOWN_DAYS));
            })
            ->update(['digest_sent_at' => now()]);

        return $affected === 1;
    }

    /**
     * @param  SupportCollection<int, string>  $organizationIds
     */
    private function buildDigest(User $user, SupportCollection $organizationIds): DigestSummary
    {
        $now = CarbonImmutable::now()->setTimezone($user->timezone);
        $weekStart = $now->subDays(7);
        $weekEnd = $now;
        $upcomingEnd = $now->addDays(7);

        return new DigestSummary(
            published: $this->fetchSection($organizationIds, PostState::PUBLISHED, 'publish_date', $weekStart, $weekEnd),
            failed: $this->fetchSection($organizationIds, PostState::ERROR, 'notified_at', $weekStart, $weekEnd),
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
        CarbonImmutable $start,
        CarbonImmutable $end,
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
