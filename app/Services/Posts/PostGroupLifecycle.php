<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Post;
use App\Models\PostGroup;
use App\Social\Dto\PostResultDto;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
     * A brand-new group. The caller mints `$id` and resolves the publish date
     * from it BEFORE this runs: jitter needs a stable identifier to seed from,
     * so the scheduled instant and the id the row is inserted with must agree.
     * `id` is deliberately absent from `$fillable`, so it is assigned as a
     * property, never through the mass-assignment array — `PostGroup::create(
     * ['id' => $id, ...])` would silently drop it and let `HasUlids` mint a
     * second, different id in its own `creating` hook, which is exactly what
     * happened before this was written as a direct assignment.
     * `HasUniqueIds::setUniqueIds()` only fills a column that is still empty,
     * so this pre-set value is what actually ships.
     *
     * No lock and no version bump, unlike every other write path: `$id` is a
     * freshly-minted ulid nothing else can reference yet, so there is no
     * concurrent writer to serialise against. A brand-new group's very first
     * schedule generation is simply version 1, set only when it matters
     * (entering QUEUE for an immediate "publish now"); a SCHEDULED group has
     * its queue_version read fresh, whatever it is, at whatever future instant
     * `DueDispatchService` claims it.
     *
     * Not `forced`: nothing older can hold this group's uniqueness lock.
     *
     * @param  Closure(PostGroup, PostState): void  $body
     */
    public function create(string $id, Intent $intent, Closure $body): Receipt
    {
        $group = DB::transaction(function () use ($id, $intent, $body) {
            $target = $this->targetStateFor($intent->kind, PostState::DRAFT);

            $group = new PostGroup([
                'state' => PostState::DRAFT,
                'publish_date' => $intent->date,
                ...$intent->recurrence,
            ]);
            $group->id = $id;
            $group->save();

            $this->transitionToward($group, $target);

            if ($target === PostState::QUEUE) {
                $group->queue_version = 1;
                $group->save();
            }

            $body($group, $target);

            return $group->fresh(['posts.media', 'tags']);
        });

        return new Receipt($group, $group->queue_version);
    }

    /**
     * The write side for the HTTP-facing paths: one Intent at a time. Returns a
     * Receipt; the caller dispatches AFTER its own transaction has committed
     * (see PostGroupService::claimAndDispatch(), until that moves here too).
     */
    public function edit(PostGroup $group, Intent $intent, ?Closure $body = null): Receipt
    {
        return match ($intent->kind) {
            IntentKind::Retry => $this->retry($group),
            IntentKind::Draft, IntentKind::Schedule, IntentKind::Now => $this->write($group, $intent, $body),
            IntentKind::Reschedule => $this->reschedule($group, $intent->date ?? throw new LogicException('Intent::reschedule() always carries a date.')),
        };
    }

    /**
     * A full-replace save (`PUT /api/posts/{group}`): lock, editability guard,
     * the caller's check, then the target state, the new date and recurrence
     * rule, the version bump, the transition, and finally `$body` — where the
     * caller drops and rebuilds the chains and tags, with the group already in
     * its new state so the new rows can be created in it. All in one
     * transaction: if `$body` throws, nothing above it persists.
     *
     * The returned Receipt is `forced` because an edit must always get a fresh
     * job when it lands on QUEUE, even if an older generation's uniqueness lock
     * is still held; for any other target nothing is dispatched at all.
     *
     * @param  Closure(PostGroup, PostState): void|null  $body
     */
    private function write(PostGroup $group, Intent $intent, ?Closure $body): Receipt
    {
        $group = DB::transaction(function () use ($group, $intent, $body) {
            // lockGroup() FIRST — before guardEditable()'s post-row lock, and
            // before anything reads state/queue_version for a decision this
            // method is about to persist. See lockGroup()'s docblock.
            $group = $this->lockGroup($group);
            $this->guardEditable($group);

            if ($intent->check !== null) {
                ($intent->check)();
            }

            $target = $this->targetStateFor($intent->kind, $group->state);

            $group->publish_date = $intent->date;
            // A PUT is a full replace, recurrence included — an intent with no
            // rule clears it, which is exactly how the editor stops a series.
            // Reaching this line means the group is still editable, so this
            // can only rewrite the rule of an occurrence that has not published.
            $group->fill($intent->recurrence);
            $this->bumpQueueVersionIfNotDraft($group, $target);
            $group->save();

            $this->transitionToward($group, $target);

            if ($body !== null) {
                $body($group, $target);
            }

            return $group->fresh(['posts.media', 'tags']);
        });

        return new Receipt($group, $group->queue_version, forced: true);
    }

    /**
     * What a save means for the group's state. A draft stays a draft and can
     * only be saved as one while it still is one: there is no way back to draft
     * once a group has been scheduled, queued, or has failed.
     */
    private function targetStateFor(IntentKind $kind, PostState $current): PostState
    {
        if ($kind === IntentKind::Draft) {
            if ($current !== PostState::DRAFT) {
                throw ValidationException::withMessages([
                    'type' => ["Cannot move a post from {$current->value} back to draft."],
                ]);
            }

            return PostState::DRAFT;
        }

        return match ($kind) {
            IntentKind::Now => PostState::QUEUE,
            IntentKind::Schedule => PostState::SCHEDULED,
            default => throw new LogicException("{$kind->name} is not a save."),
        };
    }

    /**
     * The calendar's drag-and-drop. The caller's request already enforces a
     * future date, so this never has to handle "drag onto right now". A DRAFT
     * or already-SCHEDULED group just gets a new `publish_date`. A QUEUE group
     * (claimed: a job already exists for it) is UN-claimed back to SCHEDULED in
     * the same write, bumping `queue_version` once so that job goes stale, and
     * its pending posts move with it. Either way nothing is dispatched here:
     * `DueDispatchService`'s next pass finds the row SCHEDULED with the new date
     * and claims it exactly once, whenever it is actually due. The stale job
     * finds `state !== QUEUE` in `claim()` and exits as a harmless no-op.
     */
    private function reschedule(PostGroup $group, CarbonInterface $publishDate): Receipt
    {
        $group = DB::transaction(function () use ($group, $publishDate) {
            $group = $this->lockGroup($group);
            $this->guardEditable($group);

            $group->publish_date = $publishDate;

            if ($group->state === PostState::QUEUE) {
                $this->bumpQueueVersionIfNotDraft($group, PostState::SCHEDULED);
                $group->save();
                $this->stateMachine->transition($group, PostState::SCHEDULED);

                // Posts move with the group — same reasoning as claim()'s
                // un-claim branch: guardEditable()'s pending-post lock only
                // means anything while these rows are genuinely QUEUE.
                Post::query()
                    ->where('post_group_id', $group->id)
                    ->whereNull('release_id')
                    ->where('state', PostState::QUEUE->value)
                    ->update(['state' => PostState::SCHEDULED->value]);
            } else {
                $group->save();
            }

            return $group;
        });

        return new Receipt($group, $group->queue_version);
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
    private function bumpQueueVersionIfNotDraft(PostGroup $group, PostState $resultingState): void
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
    private function transitionToward(Post|PostGroup $model, PostState $target): void
    {
        if ($model->state === $target) {
            return;
        }

        if ($target === PostState::QUEUE && $model->state !== PostState::SCHEDULED) {
            $this->stateMachine->transition($model, PostState::SCHEDULED);
        }

        $this->stateMachine->transition($model, $target);
    }

    /**
     * A group may be replaced, rescheduled, or deleted only while every
     * one of its segments has never been released — see this class's
     * docblock. Callers MUST call `lockGroup()` first and pass its
     * result here, inside the SAME transaction — this method's own
     * post-row lock only means anything as the SECOND lock acquired
     * (see `lockGroup()`'s docblock on lock order), and `$group->state`
     * below is trusted directly (no separate `fresh()` read) precisely
     * because `lockGroup()` already guarantees it is current.
     *
     * Task 31 hardening — deleting (or editing) a group mid-publish: an
     * earlier revision of this check was a single unlocked read
     * (`$group->posts()->whereNotNull('release_id')->exists()`), which
     * left a real window open. `PublishService::publishChannel()` commits
     * per CHANNEL, not for the whole group (see that class's docblock),
     * so a group can sit with SOME channels already published and others
     * still `QUEUE` while a `publishChannel()` transaction for one of the
     * remaining channels is genuinely in flight — lock acquired on its
     * pending rows, provider call made, `release_id` not yet committed.
     * An unlocked read landing in exactly that gap would see "nothing
     * published yet" for that channel, and a subsequent delete/replace
     * would then discard the very rows that transaction is about to mark
     * `PUBLISHED` — not a duplicate publish, but a message that is live
     * on the network with NO local record it was ever sent, and (for
     * delete) the whole group gone. The `lockForUpdate()` read below
     * closes that: it targets the exact same predicate
     * `publishChannel()`'s own re-lock uses (`state = QUEUE AND
     * release_id IS NULL`), so this method is forced to wait for any such
     * in-flight transaction to commit or roll back before its own
     * `hasPublishedSegment` check runs — at which point that check, under
     * Postgres READ COMMITTED, sees the freshly-committed truth rather
     * than a stale snapshot. Proven directly with real OS-level
     * concurrency (a sequential Pest call cannot reproduce this race) in
     * `tests/Feature/Publishing/RescheduleTest.php`.
     *
     * ==========================================================
     * INVARIANT — do not add a `queue_version`/`publish_date` write
     * path that skips this method
     * ==========================================================
     *
     * EVERY path that bumps `queue_version` or moves `publish_date` on a
     * QUEUE group MUST take that group's pending post rows FOR UPDATE
     * first — in practice, by calling this method. This is not only
     * about the delete/replace case above; it is half of the fix for
     * final-review Critical 2.
     *
     * `PublishService::publishChannel()` re-reads `post_groups` with a
     * PLAIN (unlocked) read inside its own transaction, immediately
     * before the provider call, and aborts the channel if the version
     * has moved on. It cannot lock that row: it already holds these post
     * locks, and a writer here holds the GROUP lock while waiting on
     * them, so taking the group lock there would close a deadlock cycle.
     * The plain read alone does NOT see a writer that has started and not
     * yet committed. What closes the window is that such a writer is
     * blocked HERE, on these post rows, until the publishing transaction
     * ends — and then re-reads and 409s on the `release_id` it just
     * committed. Either the publisher sees the new version and abandons
     * the channel, or the writer sees the release and is refused.
     *
     * A new bump path that skips these row locks (a bulk reschedule, an
     * admin tool, a future "move all posts" feature) breaks that pairing
     * silently: the publisher's read would miss the bump, publish at the
     * old time, and nothing in the test suite would necessarily go red.
     * Route it through `lockGroup()` + this method, as every current
     * caller does. `retry()` is the one path that legitimately does not
     * call this method — it accepts ONLY a group already in `ERROR`, for
     * which no run can be in flight by construction — and
     * `MissedPostsService` never bumps at all.
     */
    public function guardEditable(PostGroup $group): void
    {
        Post::query()
            ->where('post_group_id', $group->id)
            ->whereNull('release_id')
            ->where('state', PostState::QUEUE->value)
            ->lockForUpdate()
            ->get();

        $hasPublishedSegment = Post::query()
            ->where('post_group_id', $group->id)
            ->whereNotNull('release_id')
            ->exists();

        if ($group->state === PostState::PUBLISHED || $hasPublishedSegment) {
            throw new ConflictHttpException('This post has already been published and can no longer be edited or deleted.');
        }
    }
}
