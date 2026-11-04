<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Post;
use App\Models\PostGroup;
use App\Social\Dto\PostResultDto;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

    /**
     * The write side for the HTTP-facing paths: one Intent at a time. Returns a
     * Receipt; the caller dispatches AFTER its own transaction has committed
     * (see PostGroupService::claimAndDispatch(), until that moves here too).
     */
    public function edit(PostGroup $group, Intent $intent): Receipt
    {
        return match ($intent->kind) {
            IntentKind::Retry => $this->retry($group),
        };
    }

    /**
     * ERROR -> QUEUE, one schedule generation later, due right now: the date
     * becomes now, the error and the notification flag are cleared (via the
     * version bump), and the failed segments that never reached the network
     * (`release_id IS NULL`) go back to QUEUE. Segments already out are left
     * exactly as they are, so a retry re-sends only what failed.
     *
     * `forced` because a retry must always get a fresh job even when an older
     * generation's uniqueness lock is still held.
     */
    private function retry(PostGroup $group): Receipt
    {
        $group = DB::transaction(function () use ($group) {
            $group = $this->lockGroup($group);

            if ($group->state !== PostState::ERROR) {
                throw ValidationException::withMessages([
                    'state' => ["Only a failed post can be retried (current state: {$group->state->value})."],
                ]);
            }

            $group->publish_date = Carbon::now();
            $group->error = null;
            $this->bumpQueueVersionIfNotDraft($group, PostState::QUEUE);
            $group->save();

            $this->transitionToward($group, PostState::QUEUE);

            $group->posts()
                ->where('state', PostState::ERROR->value)
                ->whereNull('release_id')
                ->get()
                ->each(function (Post $post): void {
                    $post->error = null;
                    $this->transitionToward($post, PostState::QUEUE);
                });

            return $group->fresh(['posts.media', 'tags']);
        });

        return new Receipt($group, $group->queue_version, forced: true);
    }

    /**
     * Re-reads and locks `$group`'s row via `lockForUpdate()`. MUST be
     * called FIRST inside the caller's own transaction — before
     * `guardEditable()`'s post-row lock, and before reading
     * `$group->state`/`$group->queue_version` for any decision this
     * method is about to persist. Two independent reasons, both from
     * Task 31 review:
     *
     *  - Authoritative `queue_version` (finding 1, CRITICAL). An earlier
     *    revision bumped `queue_version` via Eloquent's own `increment()`,
     *    called AFTER the caller's transaction had already committed —
     *    the docblock there claimed `increment()` "refreshes the
     *    in-memory attribute to match" the row's real committed value;
     *    that was simply wrong. `increment()` issues an atomic
     *    `UPDATE ... SET queue_version = queue_version + 1` in the
     *    database, but sets its OWN in-memory attribute to (whatever
     *    stale value this PHP object already held) + 1 — it never
     *    re-reads the row. Two concurrent reschedules of the same group
     *    (A and B both load `queue_version = 5`; B commits date T2,
     *    increments — DB 6, its own memory 6 — dispatches v6; A commits
     *    date T1 afterwards, increments from ITS stale in-memory 5 — DB
     *    now 7, but A's memory is only 6 — dispatches v6) leaves the row
     *    at version 7 while BOTH dispatched jobs carry v6: neither
     *    matches, neither ever publishes, and because a version mismatch
     *    is a silent no-op (never a failure), nothing reaches
     *    `failed_jobs` either — the group sits at `QUEUE` forever. This
     *    was a regression: before Task 31, both jobs were live and each
     *    re-read `publish_date` under the lock, so whichever one matched
     *    the current schedule published; the version-based replacement
     *    must be at least as robust. Locking the row here, then bumping
     *    the LOCKED instance's own already-current in-memory value by a
     *    plain `+ 1` (see `bumpQueueVersionIfNotDraft()`) and saving it
     *    together with `publish_date` in the SAME transaction, restores
     *    that: the lock forces a concurrent caller to wait until this
     *    transaction commits, then re-read the row fresh, so the
     *    LAST committer's write — date and version together — is always
     *    what every subsequent reader (including the next reschedule,
     *    and `PostGroupLifecycle::claim()`) sees, and no
     *    dispatched version is ever computed from a value another
     *    transaction has since moved past. Covered by
     *    `RescheduleTest`'s two-concurrent-`updateDate()` fork test.
     *  - Lock ORDER (finding 4). `PublishService::abandonGroup()` locks
     *    the group row, then post rows, inside one transaction. Every
     *    caller in this class now does the same — this method first,
     *    `guardEditable()`'s post-row lock second — because the reverse
     *    order (an earlier revision's post-lock-then-unlocked-group-read)
     *    is a deadlock cycle waiting to happen the moment the two run
     *    concurrently against the same group: Postgres detects it and
     *    aborts one side, a retryable job on one hand, a 500 on a
     *    calendar drag on the other.
     */
    public function lockGroup(PostGroup $group): PostGroup
    {
        /** @var PostGroup $locked */
        $locked = PostGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /**
     * Bumps `$group->queue_version` by exactly 1, in memory, on the
     * object the caller already holds locked via `lockGroup()` earlier in
     * the SAME transaction. A plain PHP `+ 1` — never `increment()`, see
     * `lockGroup()`'s docblock for why that was the bug — is exactly
     * correct here specifically BECAUSE `$group` was read under
     * `lockForUpdate()`: no other transaction can have changed this row
     * since (the lock forces it to wait), so this object's current
     * in-memory value IS the row's authoritative committed value. The
     * caller's own `save()` immediately after (alongside `publish_date`)
     * persists both together, atomically, in the order they belong —
     * never as a separate write issued after commit, which is what let
     * the date and the version drift out of sync under a race.
     *
     * A no-op unless `$resultingState` is `SCHEDULED` or `QUEUE`: nothing
     * about a `DRAFT` write needs a fresh dispatch generation, since
     * nothing is about to be dispatched for it. Durable-scheduling
     * rewrite (widened from "unless QUEUE" alone): a `type=schedule` edit
     * of an already-`QUEUE` group now lands on `SCHEDULED`, not `QUEUE`
     * — see `updateDate()`/`update()` — so "re-entering the pipeline"
     * has to cover both resulting states, or a schedule-edit-away-from-
     * ERROR would skip the `notified_at` reset below exactly the way
     * Task 32 review finding 1 originally found for QUEUE. Bumping the
     * version on a `SCHEDULED` result is harmless even where it isn't
     * strictly needed (`DueDispatchService` reads whatever value is
     * current at claim time regardless): it still correctly invalidates
     * any job dispatched under an OLDER generation of this row.
     *
     * Task 32 review finding 1 (CRITICAL): also clears `notified_at`
     * here, in the SAME place `queue_version` is bumped — every path
     * that re-enters the publish pipeline (a fresh dispatch generation)
     * goes through this one method, `retry()` included. Without this, a
     * group `MissedPostsService::notifyFailed()` already emailed once
     * stays permanently un-notifiable: the customer retries, it fails
     * again, `notifyFailed()`'s `whereNull('notified_at')` filter skips
     * it forever, and — because `retry()` also clears `error` on the way
     * back into the pipeline — `post_groups.error` never gets rewritten
     * either (`buildErrorSummary()` is gated on the exact same
     * never-reached notify pass), leaving `GET /api/posts/errors` and
     * the calendar rendering a genuinely re-failed post with no reason
     * at all. Resetting it here, alongside the version bump, is what
     * makes "this is a fresh generation" apply to the notify flag too,
     * not just to `PublishPostJob`'s own staleness check.
     */
    public function bumpQueueVersionIfNotDraft(PostGroup $group, PostState $resultingState): void
    {
        if ($resultingState !== PostState::DRAFT) {
            $group->queue_version = $group->queue_version + 1;
            $group->notified_at = null;
        }
    }

    /**
     * Transitions `$model` toward `$target`, inserting the mandatory
     * `SCHEDULED` hop first when jumping straight from `DRAFT`/`ERROR`
     * to `QUEUE` — `PostStateMachine::TRANSITIONS` has no direct
     * `DRAFT->QUEUE` or `ERROR->QUEUE` entry (every claim durably passes
     * through `SCHEDULED`, even one this class immediately claims itself
     * for `type=now`/retry). A no-op when `$model` is already at
     * `$target`, so every call site can call this unconditionally rather
     * than repeating `if ($target !== $model->state)` at each one.
     */
    public function transitionToward(Post|PostGroup $model, PostState $target): void
    {
        if ($model->state === $target) {
            return;
        }

        if ($target === PostState::QUEUE && $model->state !== PostState::SCHEDULED) {
            $this->stateMachine->transition($model, PostState::SCHEDULED);
        }

        $this->stateMachine->transition($model, $target);
    }
}
