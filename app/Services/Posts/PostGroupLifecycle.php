<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one module that decides what may happen to a PostGroup's publish
 * lifecycle: who may claim it for publishing, how a claim is undone, how its
 * `queue_version` moves. docs/plans/architecture-deepening.md (candidates 1+2)
 * describes where this is going; call sites move in one at a time, so for now
 * it owns the claim guard and the due-date predicate, and the rest still lives
 * in PostGroupService / PublishService / DueDispatchService / MissedPostsService.
 *
 * `PostState::PUBLISHED` is terminal: nothing in this class may ever move a
 * group out of it. Publishing a customer's post twice is the worst failure
 * this product can produce.
 *
 * Pipeline paths run with no `OrganizationContext`, so they remove the tenant
 * scope BY NAME (`withoutGlobalScope('organization')`), never in bulk.
 */
class PostGroupLifecycle
{
    /**
     * How far into the future a group's `publish_date` may be and still count
     * as due. `DueDispatchService` claims groups slightly ahead of time (its
     * lookahead exists so a per-minute tick never lands a job a few seconds
     * early and bounces it), so a job that fires a hair before the exact
     * instant must still publish; a date materially further out means the
     * group has been rescheduled since and the job is stale.
     */
    private const int PUBLISH_DATE_GRACE_SECONDS = 5;

    /**
     * Locks and returns the group iff it is currently `QUEUE` AND due —
     * `publish_date` is not materially in the future (see
     * `PUBLISH_DATE_GRACE_SECONDS`); returns null (and does nothing else)
     * for anything else, except the one case documented below that un-claims.
     * Deliberately its own short transaction, not the one wrapping a whole
     * publish run: the lock is committed the moment this returns.
     *
     * `$queueVersion` is compared to `$group->queue_version` INSIDE this same
     * `lockForUpdate()` transaction, not before it and not by the caller
     * beforehand — that placement is the entire point. Every (re)dispatch of
     * `PublishPostJob` bumps this column and hands the freshly-bumped value to
     * that specific job instance. A job holding an OLDER value is, by
     * construction, one whose schedule generation has since been superseded —
     * most commonly by a reschedule, but the identical mechanism also covers
     * "two instances of this job somehow both reach the queue for the same
     * group". Because the comparison happens under the SAME row lock as the
     * state check, and against the value physically stored on the row at that
     * exact instant, a stale job cannot slip past it even if it wins the race
     * to acquire the lock first. This closes a gap the due-date check does
     * not: a group can be rescheduled to a new date that has ALSO already
     * passed by the time the stale job fires (`isDueNow()` only rejects a date
     * materially in the FUTURE).
     *
     * Order matters, and is pinned by PostGroupLifecycleTest: existence, then
     * state, then version, then due date. A stale job returns at the version
     * check and never reaches the un-claim below.
     *
     * The due-date check is what makes a STALE job — one dispatched for an old
     * `publish_date` that has since been moved into the future — harmless
     * regardless of whether a fresh job was ever successfully dispatched for
     * the new date. A job that returns here because its group isn't due yet
     * does nothing further — no retry, no error — since either a fresh job is
     * on the way for the correct time, or (the one residual gap this does not
     * close) none was dispatched at all and nothing will publish this group
     * until something else touches it. That is a missed-publish failure mode,
     * not a duplicate-publish one.
     */
    public function claim(string $postGroupId, ?int $queueVersion): ?PostGroup
    {
        return DB::transaction(function () use ($postGroupId, $queueVersion): ?PostGroup {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null) {
                Log::info('publish.guard_skipped', [
                    'post_group_id' => $postGroupId,
                    'reason' => 'group_not_found',
                ]);

                return null;
            }

            if ($group->state !== PostState::QUEUE) {
                Log::info('publish.guard_skipped', [
                    'post_group_id' => $group->id,
                    'reason' => 'not_queued',
                    'state' => $group->state->value,
                ]);

                return null;
            }

            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                Log::info('publish.guard_skipped', [
                    'post_group_id' => $group->id,
                    'reason' => 'stale_queue_version',
                    'job_queue_version' => $queueVersion,
                    'current_queue_version' => $group->queue_version,
                ]);

                return null;
            }

            if (! $this->isDueNow($group)) {
                Log::info('publish.guard_skipped', [
                    'post_group_id' => $group->id,
                    'reason' => 'not_due_yet',
                    'publish_date' => $group->publish_date?->toIso8601String(),
                ]);

                // This claim's group has since been rescheduled materially
                // forward — normally impossible to observe here at all,
                // because every PostGroupService reschedule path (update(),
                // updateDate()) already un-claims a QUEUE group back to
                // SCHEDULED the instant it moves publish_date, well before
                // this job ever gets this far. Kept as a defensive backstop
                // for whatever that invariant doesn't cover — a direct DB
                // write, a future bug — rather than leaving the row stranded
                // at QUEUE with no live job until MissedPostsService's
                // stuck-row scan eventually notices it.
                PostGroup::withoutGlobalScope('organization')
                    ->whereKey($group->id)
                    ->update(['state' => PostState::SCHEDULED->value, 'last_dispatched_at' => null]);

                // Posts move with the group — guardEditable()'s pending-post
                // lock (state=QUEUE AND release_id IS NULL) exists to protect a
                // genuinely in-flight segment; leaving these at QUEUE after the
                // group itself has un-claimed would make every future edit lock
                // rows that are not actually being worked on by anything.
                Post::withoutGlobalScope('organization')
                    ->where('post_group_id', $group->id)
                    ->whereNull('release_id')
                    ->where('state', PostState::QUEUE->value)
                    ->update(['state' => PostState::SCHEDULED->value]);

                return null;
            }

            return $group;
        });
    }

    /**
     * True iff `$group->publish_date` is not materially in the future — see
     * `PUBLISH_DATE_GRACE_SECONDS`. A single shared predicate between `claim()`
     * (should this run publish anything at all right now?) and the
     * retries-exhausted path (should it be allowed to settle this group to
     * ERROR right now?): an earlier revision applied this check only in the
     * claim, so a stale, already-superseded job's exhausted retries could mark
     * a group `ERROR` even though it had since been rescheduled further into
     * the future. Keeping this as one method both callers share is deliberate:
     * two checks answering a subtly different question ("act now?" vs.
     * "settle now?") from independently-maintained copies of the same
     * arithmetic is exactly how they would eventually drift apart.
     */
    public function isDueNow(PostGroup $group): bool
    {
        if ($group->publish_date === null) {
            return true;
        }

        return ! $group->publish_date->isAfter(now()->addSeconds(self::PUBLISH_DATE_GRACE_SECONDS));
    }
}
