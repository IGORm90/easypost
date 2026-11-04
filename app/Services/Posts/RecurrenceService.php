<?php

namespace App\Services\Posts;

use App\Models\PostGroup;
use App\Notifications\RecurrencePausedNotification;
use App\Services\Billing\Exceptions\PlanLimitExceededException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Block 4: recurring publication.
 *
 * A repeating post is never expanded into a series of rows up front. The
 * next occurrence is created only once its predecessor has actually
 * published, for three reasons: a year of daily posts would otherwise
 * bury the calendar, an edit would have to be applied to every future
 * copy at once, and the organization's whole monthly post quota
 * (`PlanLimit::POSTS_PER_MONTH`) would be spent by a single save.
 *
 * Two consequences worth stating, because they remove work rather than
 * add it: at most ONE future occurrence exists at any moment, so there
 * is no "delete this one or the whole series?" question to ask; and the
 * recurrence rule lives on that pending occurrence and is inherited by
 * its successor, so editing it is "this and all future ones" by
 * construction, with no second option to offer.
 *
 * This class owns *when* and *whether*; `PostGroupService::spawnRecurrence()`
 * owns the INSERT itself, so that `PlanLimitService` keeps being called
 * only from the short allow-list in `PlanLimitControllerIsolationTest`.
 */
class RecurrenceService
{
    public function __construct(
        private readonly PostGroupService $groups,
    ) {}

    /**
     * Creates the successor of `$postGroupId`, if it has one. Called
     * from `PublishService::publishGroup()` after — and only after — the
     * group has settled into `PUBLISHED`, outside that transaction.
     *
     * THIS METHOD NEVER THROWS. By the time it runs, the network already
     * has the post. An exception escaping here would take
     * `PublishPostJob` down its `failed()` path, which calls
     * `PublishService::PostGroupLifecycle::abandon()` and writes a row to `failed_jobs`
     * — recording as a failure the one thing that unambiguously
     * succeeded, and (worse) presenting a publishing failure to an
     * operator who would go looking for a post that went out perfectly.
     * Everything below is inside one catch-all, and the only reporting
     * channel is the log.
     *
     * IDEMPOTENCY. `PublishPostJob` is re-executed as a matter of course
     * — Horizon retries, Redis redeliveries, a manual retry from the
     * dashboard. `PostState::PUBLISHED` being terminal stops a second
     * run from publishing anything twice, but it does NOT stop it from
     * spawning a second successor: nothing about "the group is already
     * published" says "and its successor already exists". The claim
     * below is what says that — a single conditional UPDATE, the same
     * shape `MissedPostsService::notifyOne()` uses to claim the right to
     * send one email. Losing the claim means another run already owns
     * this occurrence's successor, and the correct response is to do
     * nothing at all. The unique index on
     * (`repeat_parent_id`, `publish_date`) is the second line behind it,
     * for a race that diverges before the UPDATE lands.
     *
     * The claim is deliberately taken BEFORE the insert, and is NOT
     * released when the insert fails: a series that could not be
     * continued is over, and retrying it on every redelivery would mail
     * the author the same warning again and again.
     */
    public function spawnNext(string $postGroupId): void
    {
        try {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->with('creator')
                ->whereKey($postGroupId)
                ->first();

            if ($group === null || $group->repeat_unit === null) {
                return;
            }

            $next = $this->nextDate($group);

            if ($next === null) {
                // The series has reached `repeat_until`. Claim anyway, so
                // a redelivery does not walk this path a second time.
                $this->claim($postGroupId);

                return;
            }

            if (! $this->claim($postGroupId)) {
                return;
            }

            try {
                $this->groups->spawnRecurrence($group, $next);
            } catch (PlanLimitExceededException $e) {
                $this->pause($group, $e);
            }
        } catch (Throwable $e) {
            // See the docblock: the publish itself already succeeded, so
            // there is nothing this failure may be allowed to undo.
            Log::error('Failed to create the next occurrence of a recurring post.', [
                'post_group_id' => $postGroupId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * True when THIS run won the right to create `$postGroupId`'s
     * successor. A conditional UPDATE rather than a read followed by a
     * write: two concurrent runs both reading `null` and both proceeding
     * is exactly the race being closed.
     */
    private function claim(string $postGroupId): bool
    {
        return PostGroup::withoutGlobalScope('organization')
            ->whereKey($postGroupId)
            ->whereNull('repeat_spawned_at')
            ->update(['repeat_spawned_at' => now()]) > 0;
    }

    /**
     * The organization ran out of monthly posts, so the series stops
     * here. Silence would be the wrong answer: a recurring post that
     * quietly stopped is noticed weeks later, by which time nothing
     * explains it. The author gets one email — one, because the claim
     * above is already taken and no redelivery will reach this line
     * again.
     */
    private function pause(PostGroup $group, PlanLimitExceededException $e): void
    {
        Log::warning('A recurring post series was paused by a plan limit.', [
            'post_group_id' => $group->id,
            'organization_id' => $group->organization_id,
            'exception' => $e->getMessage(),
        ]);

        $group->creator?->notify(new RecurrencePausedNotification($group));
    }

    /**
     * The instant the next occurrence of `$group`'s series should
     * publish at, or null when there is no next occurrence — either the
     * group does not repeat at all, or the next one would land past
     * `repeat_until`.
     *
     * Pure: no database access, no side effects, no dependency on the
     * current time. That is what lets the rules below be tested directly
     * rather than only through a publish.
     *
     * THE SHIFT IS COMPUTED IN THE AUTHOR'S TIMEZONE, not in UTC.
     * `publish_date` is a `timestamptz` — an absolute instant — and
     * adding 7×86400 seconds to it moves a post by an hour across every
     * daylight-saving boundary, relative to the time the user actually
     * sees in the calendar (`CalendarGrid.vue` renders every entry in
     * `auth.user.timezone`). A weekly post at 09:00 must stay at 09:00.
     * `users.timezone` defaults to 'UTC' and is validated against the
     * real IANA list (`UpdateProfileRequest`), so the only way to get
     * nothing here is an author who has been deleted — `created_by` is
     * `nullOnDelete()` — and that falls back to UTC rather than throwing.
     */
    public function nextDate(PostGroup $group): ?CarbonInterface
    {
        $unit = $group->repeat_unit;
        $interval = $group->repeat_interval;
        $from = $group->publish_date;

        if ($unit === null || $interval === null || $interval < 1 || $from === null) {
            return null;
        }

        // `->`, not `?->`: an author deleted after the series started
        // (`created_by` is nullOnDelete()) leaves `creator` null, and
        // `??` already suppresses the read on a null left-hand side —
        // isset() semantics, no warning, no fatal. The nullsafe operator
        // in front of `??` is redundant, and static analysis says so.
        $timezone = $group->creator->timezone ?? 'UTC';

        $next = $unit->advance(Carbon::instance($from->toDateTime())->setTimezone($timezone), $interval)->utc();

        // Inclusive: an occurrence landing exactly on `repeat_until` is
        // the last one of the series, not one past its end.
        if ($group->repeat_until !== null && $next->greaterThan($group->repeat_until)) {
            return null;
        }

        return $next;
    }
}
