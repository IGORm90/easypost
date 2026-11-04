<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Post;
use App\Models\PostGroup;
use App\Social\Dto\PostResultDto;
use Illuminate\Database\Eloquent\Collection;
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
    public function __construct(private readonly PostStateMachine $stateMachine) {}

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

    /**
     * The other half of "a job that fires against a stale schedule must
     * never publish" (review finding 1): everything still `QUEUE` in
     * `$postGroupId` moves to `ERROR` with `$reason`, and the group is
     * finalized. Called from `PublishPostJob::failed()` once retries are
     * exhausted (see that class) — without this, a group that never
     * manages to publish sits at `QUEUE` forever with no error recorded
     * anywhere, which the calendar/API render identically to "still
     * scheduled, nothing wrong yet" (review finding 3).
     *
     * A no-op if the group is no longer `QUEUE` by the time this runs —
     * e.g. a run that was mid-flight when the job's LAST attempt failed
     * already published or errored some/all channels and finalized the
     * group itself; nothing here should second-guess that outcome.
     */
    public function abandon(string $postGroupId, string $reason, ?int $queueVersion = null): void
    {
        DB::transaction(function () use ($postGroupId, $reason, $queueVersion): void {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null) {
                Log::info('publish.abandon_guard_skipped', [
                    'post_group_id' => $postGroupId,
                    'reason' => 'group_not_found',
                ]);

                return;
            }

            if ($group->state !== PostState::QUEUE) {
                Log::info('publish.abandon_guard_skipped', [
                    'post_group_id' => $group->id,
                    'reason' => 'not_queued',
                    'state' => $group->state->value,
                ]);

                return;
            }

            // Task 31: same reasoning as PostGroupLifecycle::claim()'s queue_version
            // check below — a stale job's OWN retries running out must not
            // be allowed to error a group that has since been superseded by
            // a new schedule generation, even in the residual case where
            // isDueNow()'s due-date grace (checked next) would not by
            // itself have caught it.
            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                Log::info('publish.abandon_guard_skipped', [
                    'post_group_id' => $group->id,
                    'reason' => 'stale_queue_version',
                    'job_queue_version' => $queueVersion,
                    'current_queue_version' => $group->queue_version,
                ]);

                return;
            }

            // Same due-date grace as PostGroupLifecycle::claim() — post-review
            // fix (finding 2, second defect). Without this, a group
            // dragged further into the future while a now-dead job for
            // its OLD schedule is still mid-retry gets marked ERROR the
            // moment that dead job exhausts and this method runs, even
            // though nothing has actually gone wrong yet — it just isn't
            // due. The redispatched job for the NEW date then finds
            // ERROR (not QUEUE) and no-ops on the state check above,
            // just like the original swallowed-redispatch bug: a post
            // that should still publish, silently doesn't. See
            // isDueNow()'s docblock for why this predicate must stay a
            // single shared source of truth between the two callers.
            if (! $this->isDueNow($group)) {
                Log::info('publish.abandon_guard_skipped', [
                    'post_group_id' => $group->id,
                    'reason' => 'not_due_yet',
                    'publish_date' => $group->publish_date?->toIso8601String(),
                ]);

                return;
            }

            // lockForUpdate() — post-review fix (finding 2, first
            // defect). Without it, this SELECT could read a snapshot
            // taken just BEFORE a concurrent, still-in-flight
            // publishGroup() run commits a segment's release_id, and the
            // subsequent markPostsError() below (a plain by-primary-key
            // UPDATE, no state predicate) would then overwrite that
            // freshly-PUBLISHED row with ERROR — no duplicate publish,
            // but the product would show a post that DID go out as
            // failed. Locking here forces this query to wait for any
            // such concurrent transaction to finish, then re-read the
            // qualifying rows fresh — exactly the same reasoning
            // publishChannel() already applies to its own pending-rows
            // read.
            $stillPending = Post::withoutGlobalScope('organization')
                ->where('post_group_id', $group->id)
                ->whereNull('release_id')
                ->where('state', PostState::QUEUE->value)
                ->lockForUpdate()
                ->get();

            $this->markPostsError($stillPending, $reason);

            // Already inside this transaction's group lock — settle()
            // re-takes it (a no-op for a lock this transaction already holds)
            // and re-checks the same two predicates this method just checked.
            $this->settle($group->id, $queueVersion);
        });
    }

    public function markPostPublished(Post $post, PostResultDto $result): void
    {
        $post->release_id = $result->releaseId;
        $post->release_url = $result->releaseUrl;
        $post->published_at = now();
        $this->stateMachine->transition($post, PostState::PUBLISHED);
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    public function markPostsError(Collection $posts, string $message): void
    {
        foreach ($posts as $post) {
            $post->error = $message;
            $this->stateMachine->transition($post, PostState::ERROR);
        }
    }

    /**
     * Reached only when no channel needs a retry — every post in the
     * group has settled into either `PUBLISHED` or `ERROR` for this run.
     * PUBLISHED iff every single one of them is; otherwise ERROR (brief
     * point 9).
     *
     * Takes an ID and re-reads the row under `lockForUpdate()` rather
     * than accepting the caller's in-memory `PostGroup` — final-review
     * fix, Critical 1, reproduced with two forked processes against real
     * Postgres (`PublishRaceTest`). `publishGroup()` locks the group in
     * `PostGroupLifecycle::claim()` and COMMITS that lock immediately (the
     * per-channel commit boundary — see the class docblock), so by the
     * time this runs, at the very end of a run that spans several
     * provider calls, the caller's instance can be arbitrarily stale.
     * `PostStateMachine::transition()` validates only the IN-MEMORY
     * `$from` and then issues a blind `save()` (UPDATE by primary key),
     * so this method used to overwrite whatever a concurrent writer had
     * committed in between. The observed outcome: an edit landing
     * mid-run (`PostGroupService::update()`, allowed through because no
     * channel had committed a `release_id` yet) recreated the group's
     * posts as `QUEUE` at `queue_version` 2 — and then THIS method,
     * still holding the version-1 instance, stamped the group `ERROR`.
     * The version-2 job then found `ERROR != QUEUE` and no-opped, and
     * nothing recovers that: `MissedPostsService::redispatchOrphaned()`
     * scans `state = QUEUE` only. The customer's edited post is lost,
     * permanently and silently.
     *
     * Both predicates are load-bearing and both are re-checked HERE,
     * under the lock, not by the caller beforehand:
     *
     *  - `state === QUEUE`: another run (or `abandon()`) may already
     *    have settled this group. Nothing may transition it out of a
     *    terminal state, and `PostStateMachine` would throw on
     *    `PUBLISHED -> *` anyway — after this run's own writes, from
     *    inside the caller's transaction in `abandon()`'s case.
     *  - `queue_version === $queueVersion`: the group has since been
     *    superseded by a NEW schedule generation, which owns the group's
     *    final state now. This run's verdict is about rows that generation
     *    has already replaced or rescheduled, and must be discarded
     *    entirely. `null` means "no opinion" (see `publishGroup()`), and
     *    then only the state check applies.
     *
     * Lock ORDER is preserved: group first, posts second — the same order
     * `PostGroupService::lockGroup()` -> `guardEditable()` and
     * `abandon()` take, so this introduces no new deadlock cycle.
     * `abandon()` calls this from INSIDE its own transaction, which
     * already holds this exact row lock; re-taking a lock the same
     * transaction holds is free, and the nested `DB::transaction()` is a
     * savepoint, not a second top-level transaction.
     */
    public function settle(string $postGroupId, ?int $queueVersion): ?PostState
    {
        return DB::transaction(function () use ($postGroupId, $queueVersion): ?PostState {
            /** @var PostGroup|null $group */
            $group = PostGroup::withoutGlobalScope('organization')
                ->whereKey($postGroupId)
                ->lockForUpdate()
                ->first();

            if ($group === null || $group->state !== PostState::QUEUE) {
                return null;
            }

            if ($queueVersion !== null && $group->queue_version !== $queueVersion) {
                return null;
            }

            $hasUnpublished = Post::withoutGlobalScope('organization')
                ->where('post_group_id', $group->id)
                ->where('state', '!=', PostState::PUBLISHED->value)
                ->exists();

            $outcome = $hasUnpublished ? PostState::ERROR : PostState::PUBLISHED;

            $this->stateMachine->transition($group, $outcome);

            return $outcome;
        });
    }
}
