<?php

namespace App\Services\Posts;

use App\Enums\PlanLimit;
use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Services\Billing\PlanLimitService;
use App\Support\OrganizationContext;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Owns every write to a `PostGroup` and its `Post` chains (Task 20).
 * Content/limit validation itself already happened in
 * StorePostRequest/UpdatePostRequest (via PostValidationService) before
 * any of this runs — this class assumes `$data['posts']` is already
 * shaped and within every provider's limits, and focuses on the three
 * things validation doesn't cover: authorization, state, and the
 * tree/transaction mechanics.
 *
 * media_ids (Task 28): each segment's `media_ids` is persisted for real,
 * as ordered rows in the `media_post` pivot (`Post::media()`) —
 * `replaceChains()`/`attachMedia()` below write `{media_id, post_id,
 * order}` for every id, in the exact order the client sent, once the
 * `Post` row itself exists. This is no longer a `settings` jsonb
 * placeholder (Task 20's original stand-in, since retired): by the time
 * this class runs, `ValidatesPostChains`' `Rule::exists('media',
 * 'id')->where('organization_id', ...)` has already proven every id both
 * exists and belongs to the current organization, so nothing here is
 * unverified client input needing a second ownership check — see that
 * trait's docblock.
 *
 * Orphaned jsonb (review Minor 7, stated no-op): this migration away
 * from Task 20's `settings->media_ids` placeholder does not migrate or
 * clear any `media_ids` key an already-persisted `Post.settings` value
 * might still carry from before this task landed — `PostGroupResource`'s
 * no-longer-stripping `channelSettings()` means such a value would now
 * be echoed back verbatim inside `posts[].settings` instead of being
 * hidden. This is a deliberate no-op, not an oversight: this is an
 * unreleased MVP with no production data, so no row anywhere actually
 * carries a leftover `settings->media_ids` key to begin with. A project
 * that already had real users at this point would need a one-off
 * migration to strip that key from every existing `Post.settings`
 * before deploying this change.
 *
 * Editability rule (the brief's central question): a group may be
 * replaced (PUT) or deleted only while NONE of its Post rows carry a
 * `release_id` — i.e. nothing in it has actually gone out to a network
 * yet. This is deliberately all-or-nothing per GROUP, not per segment:
 * a chain that mixes "already released" and "not yet released" segments
 * is exactly the partial-failure/retry shape Task 30 owns, and letting
 * PUT silently keep the released segments while replacing the rest would
 * both require rewriting Task 30's retry logic here and risk the very
 * thing `posts_release_id_immutable` (Post::RELEASE_ID_IMMUTABLE_SQLSTATE)
 * exists to prevent — this class simply never attempts to UPDATE or
 * DELETE a row once any row in the group has a release_id, so that
 * trigger is never hit from here at all. A caller that needs to edit or
 * remove content that's already partially published has no path through
 * this service; that gap is deliberate, not an oversight, and belongs to
 * whatever future task builds the "resend/retry a partially-failed post"
 * flow, which needs a purpose-built API of its own, not a reinterpreted
 * PUT.
 *
 * `type=now`: PostGroupService always stamps `publish_date` to `now()`
 * for `type=now`, ignoring whatever value the client sent (validated as
 * optional/unchecked by ValidatesPostChains) — "now" is an instruction,
 * not a specific instant the client gets to pick.
 */
class PostGroupService
{
    public function __construct(
        private readonly PostStateMachine $stateMachine,
        private readonly PlanLimitService $planLimits,
    ) {}

    /**
     * `withLimit()` (Task 36) replaces this method's own `DB::transaction()`
     * outright — there is no separate plain transaction underneath it,
     * because the whole body here is exactly the "actual INSERT" the plan
     * limit is meant to guard: a handful of local `PostGroup`/`Post`
     * writes, no network/provider calls (unlike the channels case — see
     * `IntegrationService::connect()`), so holding the organization row
     * lock across all of it is bounded and cheap, not the "lock across
     * slow I/O" mistake Task 35 review warned against for `connect()`.
     * `$organization` comes from `OrganizationContext`, not a parameter,
     * because `create()`'s existing signature (`array $data`, no
     * organization) is itself the codebase's established convention here
     * — `PostGroup`'s own `organization_id` is filled the same way, via
     * `BelongsToOrganization`'s `creating()` hook — so this does not
     * introduce a second, divergent way of learning the current tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PostGroup
    {
        $organization = $this->currentOrganization();

        /** @var PostGroup $group */
        $group = $this->planLimits->withLimit($organization, PlanLimit::POSTS_PER_MONTH, 1, function () use ($data) {
            $this->resolveIntegrations($data['posts']);

            $targetState = $data['type'] === 'draft' ? PostState::DRAFT : PostState::QUEUE;

            $group = PostGroup::create([
                'state' => PostState::DRAFT,
                'publish_date' => $this->resolvePublishDate($data['type'], $data['publish_date'] ?? null),
            ]);

            if ($targetState !== PostState::DRAFT) {
                $this->stateMachine->transition($group, $targetState);
            }

            // No lockGroup()/bumpQueueVersionIfQueuing() needed here,
            // unlike every other write path below: $group->id is a
            // freshly-minted ulid nothing else can reference yet, so
            // there is no concurrent writer to serialise against. A
            // brand-new group's very first schedule generation is
            // simply version 1.
            if ($targetState === PostState::QUEUE) {
                $group->queue_version = 1;
                $group->save();
            }

            $this->replaceChains($group, $data['posts'], $targetState);

            if (array_key_exists('tags', $data)) {
                $group->tags()->sync($data['tags'] ?? []);
            }

            return $group->fresh(['posts.media', 'tags']);
        });

        $this->dispatchPublishIfQueued($group);

        return $group;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PostGroup $group, array $data): PostGroup
    {
        $group = DB::transaction(function () use ($group, $data) {
            // lockGroup() FIRST — before guardEditable()'s post-row lock,
            // and before anything reads $group->state/queue_version for a
            // decision this method is about to persist. See lockGroup()'s
            // docblock (Task 31 review findings 1 and 4).
            $group = $this->lockGroup($group);
            $this->guardEditable($group);
            $this->resolveIntegrations($data['posts']);

            $targetState = $this->resolveTargetState($group->state, $data['type']);

            // Full replace: every existing row is dropped first. Safe
            // only because guardEditable() above already proved none of
            // them carries a release_id — see this class's docblock.
            $group->posts()->delete();

            $group->publish_date = $this->resolvePublishDate($data['type'], $data['publish_date'] ?? null);
            $this->bumpQueueVersionIfQueuing($group, $targetState);
            $group->save();

            if ($targetState !== $group->state) {
                $this->stateMachine->transition($group, $targetState);
            }

            $this->replaceChains($group, $data['posts'], $targetState);

            if (array_key_exists('tags', $data)) {
                $group->tags()->sync($data['tags'] ?? []);
            }

            return $group->fresh(['posts.media', 'tags']);
        });

        // forceRedispatch: true — see dispatchPublishIfQueued()'s
        // docblock. update() can move an already-QUEUE group's
        // publish_date exactly like updateDate() can (schedule type,
        // new publish_date in the payload), so it carries the identical
        // swallowed-redispatch risk and needs the identical fix.
        $this->dispatchPublishIfQueued($group, forceRedispatch: true);

        return $group;
    }

    /**
     * Copies `$source`'s chains verbatim into a brand-new DRAFT group:
     * fresh ids throughout, and none of the publish history
     * (`release_id`/`release_url`/`error`/`published_at`) — a duplicate
     * is new, unpublished content, never a second claim on an existing
     * release.
     *
     * `withLimit()` (Task 36 fix round 1, Critical 1) replaces the plain
     * `DB::transaction()` this method used to open on its own, for
     * exactly the same reason `create()` already does (see that
     * method's docblock): the row this closure inserts is counted by
     * `PlanLimitService::postCount()` exactly like any other `PostGroup`
     * — a duplicate is one more post against the monthly limit, not a
     * copy operation the limit doesn't apply to. Without this, an
     * organization sitting exactly at its monthly ceiling could still
     * duplicate its way past it indefinitely, since `create()`'s guard
     * only ever saw the ORIGINAL posts, never the copies.
     */
    public function duplicate(PostGroup $source): PostGroup
    {
        $organization = $this->currentOrganization();

        return $this->planLimits->withLimit($organization, PlanLimit::POSTS_PER_MONTH, 1, function () use ($source) {
            $group = PostGroup::create([
                'state' => PostState::DRAFT,
                'publish_date' => $source->publish_date,
            ]);

            $source->loadMissing('posts', 'posts.media', 'tags');

            // posts() is ordered by `order` ascending; within any one
            // chain a parent's order is always strictly less than its
            // children's, so this single ascending pass always creates
            // a parent before the child that references it, regardless
            // of how many other channels' chains are interleaved in the
            // same list.
            $idMap = [];
            foreach ($source->posts as $post) {
                $newParentId = $post->parent_post_id !== null ? ($idMap[$post->parent_post_id] ?? null) : null;

                $copy = Post::create([
                    'post_group_id' => $group->id,
                    'organization_id' => $group->organization_id,
                    'integration_id' => $post->integration_id,
                    'parent_post_id' => $newParentId,
                    'order' => $post->order,
                    'content' => $post->content,
                    'settings' => $post->settings,
                    'state' => PostState::DRAFT,
                ]);

                // Media attachments are copied along with the segment,
                // same order preserved (`$post->media` is already
                // ordered by the pivot's `order` column — see
                // Post::media()) — a duplicate is a full copy of the
                // content, not a copy missing its images.
                $this->attachMedia($copy, $post->media->pluck('id')->all());

                $idMap[$post->id] = $copy->id;
            }

            $group->tags()->sync($source->tags->pluck('id'));

            return $group->fresh(['posts.media', 'tags']);
        });
    }

    /**
     * The calendar's drag-and-drop endpoint (`PATCH .../date`). Fixed
     * post-review (finding 1): moving `publish_date` here used to
     * dispatch nothing, leaving whatever `PublishPostJob` was already
     * queued for the OLD date to fire at the old time regardless — for a
     * `QUEUE` group, that meant a rescheduled post being published early,
     * at the time it was originally scheduled for rather than the new
     * one. `dispatchPublishIfQueued()` below is a no-op for a `draft`
     * group (same guard `create()`/`update()` use), so this stays safe
     * to call unconditionally.
     *
     * `forceRedispatch: true` — second post-review correction (finding
     * 1, round 2). `PublishPostJob`'s `ShouldBeUnique` lock is a real
     * `Cache` lock acquired at DISPATCH time with a fixed 3600s TTL,
     * completely independent of `->delay()` — see
     * `Illuminate\Bus\UniqueLock::acquire()`. Without force-releasing it
     * first, a multi-drag calendar session (the normal way this endpoint
     * is used, not an edge case) would see only the FIRST drag's
     * dispatch actually reach the queue: every subsequent drag within
     * the following hour silently drops (`UniqueJobSkipped`, now logged
     * — see `App\Listeners\LogUniqueJobSkipped` — but still dropped),
     * leaving the job for the FIRST drag's date as the only one that
     * will ever run. Dragging later than that stale job's date means it
     * exits harmlessly on `PublishService::lockGroupIfQueued()`'s
     * due-date guard (see that method) and — because a plain `return`
     * from `handle()` is a SUCCESSFUL completion, not a failure —
     * finishes, releases its own lock, and is gone: nothing in
     * `failed_jobs`, `failed()` never runs, and the post is never
     * published, with no record anywhere that it should have been.
     * Dragging earlier publishes hours late. `dispatchPublishIfQueued()`
     * below force-releases the lock immediately before its own dispatch
     * call, so THIS dispatch always wins the race for the lock — the
     * worst remaining case is a stale job from an even-earlier drag that
     * simply no-ops on the due-date guard once this one supersedes it.
     * That guard, plus `PublishService::publishChannel()`'s per-row
     * `lockForUpdate()`/`release_id` filter, are what keep this safe
     * against the duplicate-publish risk force-releasing a lock would
     * otherwise reopen — see `dispatchPublishIfQueued()`'s docblock.
     */
    public function updateDate(PostGroup $group, CarbonInterface $publishDate): PostGroup
    {
        $group = DB::transaction(function () use ($group, $publishDate) {
            $group = $this->lockGroup($group);
            $this->guardEditable($group);

            $group->publish_date = $publishDate;
            $this->bumpQueueVersionIfQueuing($group, $group->state);
            $group->save();

            return $group;
        });

        $this->dispatchPublishIfQueued($group, forceRedispatch: true);

        return $group;
    }

    /**
     * `POST /api/posts/{group}/retry` (Task 31) — the manual "try again"
     * a customer clicks after a group settled to `ERROR` (some or all of
     * its channels failed; see `PublishService::finalizeGroupState()`).
     * Only `ERROR -> QUEUE` is accepted; every other current state is a
     * 422, not a silent no-op — a customer clicking retry on a group
     * that's still `QUEUE` (nothing has failed yet) or already
     * `PUBLISHED` (nothing left to retry) needs to be told clearly that
     * neither is the action they think it is, not have the request
     * quietly do nothing.
     *
     * Segment-level, this is deliberately narrow: only `Post` rows still
     * `ERROR` (which — under this codebase's invariants, see
     * `Post`/`PostStateMachine`'s docblocks — always carry a null
     * `release_id`; nothing ever sets `ERROR` on a row that already went
     * out) move back to `QUEUE`. A sibling segment or channel that
     * already succeeded stays exactly `PUBLISHED`, completely untouched
     * by this method — it is `PublishService::publishChannel()`'s own
     * `state = QUEUE AND release_id IS NULL` filter (hardened for
     * exactly this endpoint — see that method's docblock) that then
     * guarantees the resulting job re-sends ONLY those rows, resuming any
     * partially-published thread anchored to its last published segment.
     *
     * `publish_date` is reset to `now()`: a retry is an immediate
     * republish, not a return to whatever the group's original schedule
     * happened to be. `error` is cleared alongside it (Task 31 review
     * finding 3) — otherwise a group that goes on to republish
     * successfully would still carry its previous failure message,
     * which `PostGroupResource` exposes verbatim; a published group
     * showing a stale "the channel rejected the post" is a product bug,
     * not a display nuance.
     *
     * The `state === ERROR` check below runs against `$group` AFTER
     * `lockGroup()` has re-read and locked its row (Task 31 review
     * finding 2) — every other guard in this pipeline
     * (`PublishService::lockGroupIfQueued()`, `abandonGroup()`,
     * `guardEditable()` above) re-checks state under `lockForUpdate()`
     * rather than trusting whatever `$group` instance the caller
     * happened to pass in; this method matches that pattern rather than
     * being the one exception a double-click could exploit (two rapid
     * retry clicks racing: without the lock, the second could still be
     * holding a pre-transition `ERROR` snapshot by the time the first's
     * job has already finalised the group to `PUBLISHED`, and pass this
     * check regardless — overwriting a `PUBLISHED` row with `QUEUE`).
     */
    public function retry(PostGroup $group): PostGroup
    {
        $group = DB::transaction(function () use ($group) {
            $group = $this->lockGroup($group);

            if ($group->state !== PostState::ERROR) {
                throw ValidationException::withMessages([
                    'state' => ["Only a failed post can be retried (current state: {$group->state->value})."],
                ]);
            }

            $this->stateMachine->transition($group, PostState::QUEUE);

            $group->publish_date = Carbon::now();
            $group->error = null;
            $this->bumpQueueVersionIfQueuing($group, $group->state);
            $group->save();

            $group->posts()
                ->where('state', PostState::ERROR->value)
                ->whereNull('release_id')
                ->get()
                ->each(function (Post $post): void {
                    $post->error = null;
                    $this->stateMachine->transition($post, PostState::QUEUE);
                });

            return $group->fresh(['posts.media', 'tags']);
        });

        $this->dispatchPublishIfQueued($group, forceRedispatch: true);

        return $group;
    }

    /**
     * Deletion mirrors the edit rule: a group is only deletable while
     * nothing in it has actually reached a network. `PostGroup::delete()`
     * cascades to its `posts` (and `post_group_tag`) at the database
     * level — see the `posts_organization_matches_group_fk` /
     * `post_group_tag` migrations — so once past the guard this is a
     * single statement, no explicit transaction needed. A group that
     * already holds a released segment is left completely untouched:
     * the network already has that post, and deleting the local row
     * would both permanently lose the anti-duplication `release_id`
     * marker and desync the local record from what was actually sent,
     * for no benefit (it does not un-publish anything remotely).
     */
    public function delete(PostGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $group = $this->lockGroup($group);
            $this->guardEditable($group);

            $group->delete();
        });

        // Task 31: nothing further to do to "cancel" whatever
        // PublishPostJob is already sitting delayed in the queue for
        // this group — see guardEditable()'s docblock for why a job that
        // fires after this point is already harmless by construction,
        // and this class's docblock ("Deletion mirrors the edit rule")
        // for why a group with any released segment could never reach
        // this line in the first place.
    }

    /**
     * `create()`'s only source of the current tenant (see that method's
     * docblock). Fails closed rather than silently falling back to
     * anything guessable: every route that reaches `create()` also
     * carries the `organization` route middleware, so a null context here
     * means a genuine programming error (a new caller that skipped that
     * middleware), not a legitimate "no organization" request this method
     * should quietly proceed without.
     */
    private function currentOrganization(): Organization
    {
        $organization = OrganizationContext::get();

        if (! $organization instanceof Organization) {
            throw new RuntimeException('PostGroupService::create() requires OrganizationContext to be set.');
        }

        return $organization;
    }

    /**
     * @param  list<array{integration_id: string, settings?: array<string, mixed>|null, value: list<array{content: string, media_ids?: list<string>}>}>  $postsData
     */
    private function resolveIntegrations(array $postsData): void
    {
        foreach ($postsData as $item) {
            // Org-scoped via BelongsToOrganization's global scope: a
            // cross-tenant id resolves to null here even though it
            // exists (ValidatesPostChains' `exists:integrations,id`
            // rule already proved that much, ignoring org scope). Per
            // the task-20 brief this is a 403, never a 422, and the
            // exception carries no detail about the integration itself.
            $integration = Integration::query()->find($item['integration_id']);

            if ($integration === null) {
                throw new AuthorizationException;
            }
        }
    }

    /**
     * `draft` without an explicit `publish_date` gets `now()` — a
     * placeholder, not a commitment (see this class's docblock and
     * SlotFinderService::isOccupied(), which deliberately excludes DRAFT
     * groups from "occupied" regardless of what this returns for one).
     * Task 24 found that leaving it `null` made a dateless draft
     * permanently invisible to `GET /api/calendar`
     * (`CalendarService::forRange()`'s `whereBetween('publish_date', ...)`
     * never matches a NULL column, in any range) — there is no other
     * screen in this MVP that lists drafts, so a draft saved without a
     * date would silently disappear. Stamping `now()` here lands it on
     * today's calendar entry instead, where Task 24's UI already renders
     * it as visually distinct (dashed border, neutral badge) from a real
     * scheduled post. A draft created WITH an explicit date keeps
     * exactly that date — this only fills in the gap when the caller
     * gave none.
     */
    private function resolvePublishDate(string $type, ?string $publishDate): ?CarbonInterface
    {
        return match ($type) {
            'now' => Carbon::now(),
            'schedule' => Carbon::parse($publishDate),
            'draft' => $publishDate !== null ? Carbon::parse($publishDate) : Carbon::now(),
            default => null,
        };
    }

    /**
     * Only two transitions are ever requested here: DRAFT (stay/enter
     * draft) and QUEUE (schedule/publish-now). Moving OUT of DRAFT is
     * always allowed (DRAFT->QUEUE); moving INTO DRAFT is only a no-op
     * (already DRAFT) — PostStateMachine has no QUEUE->DRAFT or
     * ERROR->DRAFT entry (see its docblock: those simply aren't
     * transitions this product supports), so a request that would
     * require one is rejected here as a normal validation failure
     * (422) rather than reaching PostStateMachine::transition() and
     * throwing InvalidStateTransition, which is reserved for
     * programming errors, not user input.
     */
    private function resolveTargetState(PostState $current, string $type): PostState
    {
        if ($type !== 'draft') {
            return PostState::QUEUE;
        }

        if ($current !== PostState::DRAFT) {
            throw ValidationException::withMessages([
                'type' => ["Cannot move a post from {$current->value} back to draft."],
            ]);
        }

        return PostState::DRAFT;
    }

    /**
     * Dispatches Task 30's `PublishPostJob` for `$group` iff it just
     * entered `QUEUE` — a no-op for `draft`. Delayed until
     * `$group->publish_date`: for `type=schedule` that is the future
     * instant the user picked; for `type=now`, `resolvePublishDate()`
     * already stamped it to (approximately) `now()`, so the delay is
     * effectively zero.
     *
     * Deliberately called AFTER `create()`/`update()`'s own
     * `DB::transaction()` has fully committed, never from inside it.
     * Two independent reasons, both hard requirements:
     *
     *  - Durability: dispatching before commit risks a worker picking
     *    the job up and querying for a group the transaction hasn't
     *    made visible yet (real risk on the redis queue used in
     *    production, where a worker is a separate process/connection
     *    that only ever sees committed data).
     *  - The sync-queue test footgun: this project's test suite runs
     *    with `QUEUE_CONNECTION=sync`, under which `dispatch()` runs the
     *    job SYNCHRONOUSLY, in this same call stack — and
     *    `App\Listeners\ClearOrganizationContext` clears
     *    `OrganizationContext` around every job boundary (Task 4). A
     *    dispatch from inside this method's own transaction closure
     *    would clear the tenant context out from under whatever
     *    BelongsToOrganization-scoped work the closure still had left to
     *    do (its own `$group->fresh(['posts.media', 'tags'])` call, for
     *    one). Dispatching only after the closure has returned avoids
     *    this entirely — this method has no further scoped work to do
     *    afterwards. Every existing Post CRUD test that schedules or
     *    publishes-now (CreatePostTest, UpdatePostTest, PostMediaTest)
     *    now calls `Queue::fake()` for exactly this reason — a real
     *    dispatch() on the sync driver would otherwise run
     *    `PublishPostJob` for real, inline, in the middle of tests that
     *    have nothing to do with publishing.
     *    `tests/Feature/Publishing/PublishPostJobTest.php` is what
     *    exercises the job for real, by constructing and calling it
     *    directly rather than through dispatch().
     *
     * $forceRedispatch (post-review addition, finding 1 round 2):
     * force-releases `PublishPostJob`'s `ShouldBeUnique` lock for `$group`
     * immediately before dispatching — see `update()`/`updateDate()`'s
     * docblocks for why a redispatch on a RESCHEDULE otherwise gets
     * silently dropped by that lock for up to an hour, which is a
     * missed-publish bug, not a merely-annoying one. `false` for
     * `create()`: a brand-new group cannot already hold a lock from an
     * earlier dispatch for the SAME group id (`uniqueId()` is the
     * group's own freshly-minted ulid), so there is nothing to release
     * there and no reason to pay the extra cache round-trip on the
     * common path. Releasing a lock that isn't held is a harmless no-op
     * either way (`Illuminate\Bus\UniqueLock::release()` -> `Cache::lock(...)
     * ->forceRelease()`, which simply deletes a cache key that may not
     * exist) — `true` is never unsafe to pass, only unnecessary on
     * `create()`.
     *
     * Safety: force-releasing this lock reopens exactly the race
     * `ShouldBeUnique` exists to prevent — a second dispatch for the
     * SAME group now can reach the queue while an earlier one is still
     * pending/executing. Two guards, both already in place, are what
     * keep that race from ever producing a duplicate publish: the due-
     * date check in `PublishService::lockGroupIfQueued()` (a stale job —
     * the one whose lock was just force-released — that pops LATE simply
     * no-ops instead of publishing early) and `publishChannel()`'s own
     * per-row `lockForUpdate()` + `release_id IS NULL` filter (even two
     * genuinely-concurrent runs of the SAME group can only ever publish
     * a given segment once — see `PublishService`'s class docblock,
     * idempotency point 4). Force-releasing without either of those
     * would have been unsafe; with both, the worst case is a stale job
     * that does nothing.
     *
     * `last_dispatched_at` (Task 32 review, round 2): stamped here, the
     * ONE place in this class that actually calls
     * `PublishPostJob::dispatch()`, immediately before that call —
     * `MissedPostsService::lockCouldStillBeLegitimate()` needs to know
     * WHEN a dispatch's `ShouldBeUnique` lock was actually acquired
     * (dispatch time, independent of `->delay()`) to bound whether a
     * still-held lock could legitimately belong to it; `publish_date`
     * alone is the wrong anchor whenever a dispatch happens well after
     * it, which every `forceRedispatch: true` caller here does by
     * construction (a reschedule to "now", a manual retry). A plain
     * column write, not folded into the caller's own transaction: this
     * method already runs deliberately AFTER that transaction commits
     * (see the class docblock), and the dispatch call immediately below
     * is about to succeed or no-op for reasons entirely independent of
     * this column, so there is nothing here worth an extra `lockForUpdate()`
     * round-trip over.
     */
    private function dispatchPublishIfQueued(PostGroup $group, bool $forceRedispatch = false): void
    {
        if ($group->state !== PostState::QUEUE) {
            return;
        }

        // Stamped BEFORE the force-release below (post-review: an
        // earlier revision placed this between release() and
        // dispatch(), widening the release->dispatch window — during
        // which a concurrent dispatch elsewhere could slip in — by one
        // extra database round trip, for no benefit. Ordering this
        // first removes that widening entirely; it has no dependency on
        // the force-release having happened first.
        PostGroup::query()->whereKey($group->id)->update(['last_dispatched_at' => now()]);

        if ($forceRedispatch) {
            app(UniqueLock::class)->release(new PublishPostJob($group->id, $group->queue_version));
        }

        PublishPostJob::dispatch($group->id, $group->queue_version)->delay($group->publish_date);
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
     *    plain `+ 1` (see `bumpQueueVersionIfQueuing()`) and saving it
     *    together with `publish_date` in the SAME transaction, restores
     *    that: the lock forces a concurrent caller to wait until this
     *    transaction commits, then re-read the row fresh, so the
     *    LAST committer's write — date and version together — is always
     *    what every subsequent reader (including the next reschedule,
     *    and `PublishService::lockGroupIfQueued()`) sees, and no
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
    private function lockGroup(PostGroup $group): PostGroup
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
     * A no-op unless `$resultingState` is `QUEUE`: nothing about a
     * `DRAFT` write needs a fresh dispatch generation, since nothing is
     * about to be dispatched for it.
     *
     * Task 32 review finding 1 (CRITICAL): also clears `notified_at`
     * here, in the SAME place `queue_version` is bumped — every path
     * that re-enters `QUEUE` (a fresh dispatch generation) goes through
     * this one method, `retry()` included. Without this, a group
     * `MissedPostsService::notifyFailed()` already emailed once stays
     * permanently un-notifiable: the customer retries, it fails again,
     * `notifyFailed()`'s `whereNull('notified_at')` filter skips it
     * forever, and — because `retry()` also clears `error` on the way
     * back into `QUEUE` — `post_groups.error` never gets rewritten
     * either (`buildErrorSummary()` is gated on the exact same
     * never-reached notify pass), leaving `GET /api/posts/errors` and
     * the calendar rendering a genuinely re-failed post with no reason
     * at all. Resetting it here, alongside the version bump, is what
     * makes "this is a fresh generation" apply to the notify flag too,
     * not just to `PublishPostJob`'s own staleness check.
     */
    private function bumpQueueVersionIfQueuing(PostGroup $group, PostState $resultingState): void
    {
        if ($resultingState === PostState::QUEUE) {
            $group->queue_version = $group->queue_version + 1;
            $group->notified_at = null;
        }
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
     */
    private function guardEditable(PostGroup $group): void
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

    /**
     * Builds every Post row for every integration's chain from scratch.
     * Assumes the group currently has no posts for any of these chains
     * (true for create(); update() deletes the old ones first).
     *
     * @param  list<array{integration_id: string, settings?: array<string, mixed>|null, value: list<array{content: string, media_ids?: list<string>}>}>  $postsData
     */
    private function replaceChains(PostGroup $group, array $postsData, PostState $rowState): void
    {
        foreach ($postsData as $item) {
            $parentId = null;

            foreach ($item['value'] as $index => $segment) {
                $post = Post::create([
                    'post_group_id' => $group->id,
                    'organization_id' => $group->organization_id,
                    'integration_id' => $item['integration_id'],
                    'parent_post_id' => $parentId,
                    'order' => $index,
                    'content' => $segment['content'],
                    'settings' => $item['settings'] ?? [],
                    'state' => $rowState,
                ]);

                $this->attachMedia($post, $segment['media_ids'] ?? []);

                $parentId = $post->id;
            }
        }
    }

    /**
     * Writes `$mediaIds` into the `media_post` pivot for `$post`, one row
     * per id, `order` set to the id's own position in the array —
     * exactly the order the client sent (or, from duplicate(), the
     * order the source segment already had). This is the single place
     * that decides what "order" means for a segment's media: not
     * insertion order, not the pivot rows' own primary key order,
     * always this explicit column — see the migration's docblock and
     * Post::media()'s `orderByPivot('order')`.
     *
     * @param  list<string>  $mediaIds
     */
    private function attachMedia(Post $post, array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

        $pivotRows = [];
        foreach ($mediaIds as $order => $mediaId) {
            $pivotRows[$mediaId] = ['order' => $order];
        }

        $post->media()->attach($pivotRows);
    }
}
