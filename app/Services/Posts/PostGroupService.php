<?php

namespace App\Services\Posts;

use App\Enums\PlanLimit;
use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Tag;
use App\Services\Billing\PlanLimitService;
use App\Support\OrganizationContext;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private readonly PostGroupLifecycle $lifecycle,
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

            $targetState = $this->targetStateForType($data['type']);

            // Minted here, explicitly, rather than left to HasUlids'
            // creating-event default: jitter (below) needs a stable
            // identifier to seed from BEFORE the row exists, so the
            // scheduled instant this method computes and the id the
            // group is actually inserted with agree. Assigned as a
            // direct property, not through the constructor/`create()`'s
            // mass-assignment array — `id` is deliberately absent from
            // `$fillable`, so `PostGroup::create(['id' => $id, ...])`
            // would silently DROP it and let `HasUlids` mint a second,
            // DIFFERENT id in its own `creating` hook, which is exactly
            // what happened before this was written as a direct
            // assignment: the jitter offset and the row's real id
            // diverged, so it publish_dated on the wrong id every time.
            // `HasUniqueIds::setUniqueIds()` only fills a column that is
            // still empty, so this pre-set value is what actually ships.
            $id = (string) Str::ulid();
            $group = new PostGroup([
                'state' => PostState::DRAFT,
                'publish_date' => $this->resolvePublishDate($data['type'], $data['publish_date'] ?? null, $id),
                ...$this->recurrenceAttributes($data),
            ]);
            $group->id = $id;
            $group->save();

            // No lockGroup()/bumpQueueVersionIfNotDraft() needed here,
            // unlike every other write path below: $group->id is a
            // freshly-minted ulid nothing else can reference yet, so
            // there is no concurrent writer to serialise against. A
            // brand-new group's very first schedule generation is
            // simply version 1 — set only when it matters (entering
            // QUEUE for an immediate "publish now"; a `SCHEDULED` group
            // has its queue_version read fresh, whatever it is, at
            // whatever future instant `DueDispatchService` claims it).
            $this->lifecycle->transitionToward($group, $targetState);

            if ($targetState === PostState::QUEUE) {
                $group->queue_version = 1;
                $group->save();
            }

            $this->replaceChains($group, $data['posts'], $targetState);

            if (array_key_exists('tags', $data)) {
                $this->syncTags($group, $data['tags'] ?? []);
            }

            return $group->fresh(['posts.media', 'tags']);
        });

        $this->claimAndDispatch($group);

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
            $group = $this->lifecycle->lockGroup($group);
            $this->guardEditable($group);
            $this->resolveIntegrations($data['posts']);

            $targetState = $this->resolveTargetState($group->state, $data['type']);

            // Full replace: every existing row is dropped first. Safe
            // only because guardEditable() above already proved none of
            // them carries a release_id — see this class's docblock.
            $group->posts()->delete();

            $group->publish_date = $this->resolvePublishDate($data['type'], $data['publish_date'] ?? null, $group->id);
            // Block 4: a PUT is a full replace, recurrence included —
            // omitting the fields clears the rule, which is exactly how
            // the editor stops a series. Reaching this line at all means
            // the group is still editable (guardEditable() above), so
            // this can only ever rewrite the rule of an occurrence that
            // has not published yet.
            $group->fill($this->recurrenceAttributes($data));
            $this->lifecycle->bumpQueueVersionIfNotDraft($group, $targetState);
            $group->save();

            $this->lifecycle->transitionToward($group, $targetState);

            $this->replaceChains($group, $data['posts'], $targetState);

            if (array_key_exists('tags', $data)) {
                $this->syncTags($group, $data['tags'] ?? []);
            }

            return $group->fresh(['posts.media', 'tags']);
        });

        // Only ever dispatches anything when $targetState resolved to
        // QUEUE (type=now) — see claimAndDispatch()'s docblock. A
        // schedule edit lands the group on SCHEDULED and dispatches
        // nothing at all: DueDispatchService's next per-minute pass
        // claims it, reading queue_version fresh at that moment, so
        // there is no stale job left to fight the way the old
        // ->delay()-based dispatch required forceRedispatch to handle.
        $this->claimAndDispatch($group, forceRedispatch: true);

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

            $this->syncTags($group, $source->tags->pluck('id')->all());

            return $group->fresh(['posts.media', 'tags']);
        });
    }

    /**
     * Block 4: the next occurrence of `$source`'s recurring series,
     * created straight into `QUEUE` and dispatched. Called only by
     * `RecurrenceService::spawnNext()`, only after `$source` itself has
     * published.
     *
     * This lives here, rather than in RecurrenceService, so that
     * `PlanLimitService` keeps being called from the short allow-list
     * `PlanLimitControllerIsolationTest` enforces — the whole point of
     * that list is that it stays short. RecurrenceService decides
     * whether and when; this method performs the INSERT and pays for it
     * against the quota, exactly as `create()` and `duplicate()` do. An
     * occurrence is a post like any other: an organization at its
     * monthly ceiling does not get extra posts by having asked for them
     * in advance.
     *
     * IT RUNS ON A QUEUE WORKER, WITH NO `OrganizationContext`. Three
     * consequences, each of which is a silent data bug if forgotten:
     *
     *  - `organization_id` is passed explicitly. The `creating` hook in
     *    BelongsToOrganization fills it from the context with `??=`, so
     *    an explicit value wins and a missing context would otherwise
     *    leave it null.
     *  - the source's segments are read with
     *    `withoutGlobalScope('organization')`. `$source->loadMissing('posts')`
     *    — what `duplicate()` does, correctly, inside a request — returns
     *    an EMPTY collection here, because the scope fails closed to zero
     *    rows with no tenant set. The successor would be created with no
     *    content at all and published as an empty post.
     *  - `$source->organization` is read the same way for the limit
     *    check. `Organization` is the tenant itself and carries no
     *    organization scope, so a plain read is safe there.
     *
     * Unlike `duplicate()` (which produces a DRAFT and dispatches
     * nothing), this transitions the new occurrence into `SCHEDULED` —
     * NOT `QUEUE`, and deliberately no dispatch call at all. Before the
     * durable-scheduling rewrite this went straight to `QUEUE` and
     * dispatched `PublishPostJob` with `->delay($publishDate)`, which for
     * a weekly/monthly series could mean a delayed job sitting in Redis
     * for weeks — exactly the durability gap this whole rewrite exists
     * to close (see `App\Enums\PostState`'s docblock). A freshly spawned
     * successor is durable the instant this transaction commits:
     * `DueDispatchService`'s per-minute claim picks it up on its own,
     * reading `queue_version` fresh at whatever future instant it
     * actually claims the row.
     */
    public function spawnRecurrence(PostGroup $source, CarbonInterface $publishDate): PostGroup
    {
        /** @var Organization $organization */
        $organization = Organization::query()->whereKey($source->organization_id)->firstOrFail();

        /** @var PostGroup $group */
        $group = $this->planLimits->withLimit($organization, PlanLimit::POSTS_PER_MONTH, 1, function () use ($source, $publishDate, $organization) {
            // Minted and assigned directly, exactly like create() — see
            // that method's comment (`id` is deliberately absent from
            // `$fillable`, so this must NOT go through mass assignment)
            // and applyJitter()'s docblock for why a fixed seed is safe
            // here specifically (spawnRecurrence() only ever runs once
            // per predecessor, behind RecurrenceService's own claim()).
            $id = (string) Str::ulid();
            $group = new PostGroup([
                'organization_id' => $organization->id,
                'state' => PostState::DRAFT,
                'publish_date' => $this->applyJitter($publishDate, $id),
                'created_by' => $source->created_by,
                // The rule travels with the occurrence — without this the
                // series would stop after exactly one hop.
                'repeat_unit' => $source->repeat_unit,
                'repeat_interval' => $source->repeat_interval,
                'repeat_until' => $source->repeat_until,
                // Every occurrence points at the FIRST post of the
                // series, never at its immediate predecessor.
                'repeat_parent_id' => $source->repeat_parent_id ?? $source->id,
            ]);
            $group->id = $id;
            $group->save();

            $this->stateMachine->transition($group, PostState::SCHEDULED);

            $this->copyChainsWithoutScope($source, $group);

            $tagIds = $source->tags()->withoutGlobalScope('organization')->pluck('tags.id')->all();
            if ($tagIds !== []) {
                $this->syncTags($group, $tagIds);
            }

            return $group->fresh(['posts.media', 'tags']);
        });

        return $group;
    }

    /**
     * `duplicate()`'s copy loop, re-read without the tenant scope and
     * landing in QUEUE instead of DRAFT — see `spawnRecurrence()`'s
     * docblock for why the scope has to come off here and nowhere else.
     *
     * The single ascending pass over `order` is what makes the `$idMap`
     * work: within one chain a parent's order is always strictly less
     * than its children's, so a parent copy always exists by the time a
     * child references it, however many channels are interleaved in the
     * list. Publish history (`release_id`/`release_url`/`error`/
     * `published_at`) is deliberately not carried over: the successor is
     * new, unpublished content, never a second claim on a release the
     * network already has.
     */
    private function copyChainsWithoutScope(PostGroup $source, PostGroup $target): void
    {
        $posts = Post::withoutGlobalScope('organization')
            ->where('post_group_id', $source->id)
            ->with(['media' => fn ($query) => $query->withoutGlobalScope('organization')])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $idMap = [];

        foreach ($posts as $post) {
            $copy = Post::create([
                'post_group_id' => $target->id,
                'organization_id' => $target->organization_id,
                'integration_id' => $post->integration_id,
                'parent_post_id' => $post->parent_post_id !== null ? ($idMap[$post->parent_post_id] ?? null) : null,
                'order' => $post->order,
                'content' => $post->content,
                'settings' => $post->settings,
                'state' => PostState::SCHEDULED,
            ]);

            $this->attachMedia($copy, $post->media->pluck('id')->all());

            $idMap[$post->id] = $copy->id;
        }
    }

    /**
     * The calendar's drag-and-drop endpoint (`PATCH .../date`).
     * `UpdatePostDateRequest` enforces `after:now`, so `$publishDate` is
     * always a real future instant — this never has to handle "drag onto
     * right now".
     *
     * Durable-scheduling rewrite: this used to be the most delicate
     * method in this class — moving a `QUEUE` group's date meant force-
     * releasing and re-dispatching a delayed `PublishPostJob`, with three
     * paragraphs of reasoning about which of two racing dispatches "wins"
     * the `ShouldBeUnique` lock. None of that exists any more. A `DRAFT`
     * or already-`SCHEDULED` group just gets a new `publish_date`; a
     * `QUEUE` group (claimed — a job already exists for it) is UN-claimed
     * back to `SCHEDULED` in the same write. Either way, nothing is
     * dispatched here at all: `DueDispatchService`'s next per-minute pass
     * finds the row `SCHEDULED` with the new date and claims it exactly
     * once, whenever it is actually due. The stale job from the OLD
     * claim (if any) finds `state !== QUEUE` on
     * `PostGroupLifecycle::claim()` and exits as a harmless no-op
     * — releasing its `ShouldBeUnique` lock the instant it does, so
     * nothing is left to race the next legitimate claim either.
     */
    public function updateDate(PostGroup $group, CarbonInterface $publishDate): PostGroup
    {
        return DB::transaction(function () use ($group, $publishDate) {
            $group = $this->lifecycle->lockGroup($group);
            $this->guardEditable($group);

            $group->publish_date = $publishDate;

            if ($group->state === PostState::QUEUE) {
                $this->lifecycle->bumpQueueVersionIfNotDraft($group, PostState::SCHEDULED);
                $group->save();
                $this->stateMachine->transition($group, PostState::SCHEDULED);

                // Posts move with the group — same reasoning as
                // PostGroupLifecycle::claim()'s un-claim branch:
                // guardEditable()'s pending-post lock only means anything
                // while these rows are genuinely QUEUE.
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
     * `transitionToward()`, not a direct `ERROR -> QUEUE` transition:
     * `PostStateMachine::TRANSITIONS` has no such entry any more (see
     * that class's docblock) — retrying now passes through `SCHEDULED`
     * first, immediately followed by the claim into `QUEUE`, both inside
     * this same transaction. Segment-level transitions below go through
     * the identical two-hop path for the same reason.
     *
     * The `state === ERROR` check below runs against `$group` AFTER
     * `lockGroup()` has re-read and locked its row (Task 31 review
     * finding 2) — every other guard in this pipeline
     * (`PostGroupLifecycle::claim()`, `abandonGroup()`,
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
        $receipt = $this->lifecycle->edit($group, Intent::retry());

        $this->claimAndDispatch($receipt->group, forceRedispatch: $receipt->forced);

        return $receipt->group;
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
            $group = $this->lifecycle->lockGroup($group);
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
     * The three recurrence fields as the request supplies them, already
     * normalised to the one shape every reader tests: no `repeat_unit`
     * means no series, and then the other two are cleared with it rather
     * than left behind as debris a later edit could resurrect.
     *
     * `repeat_parent_id`/`repeat_spawned_at` are deliberately absent —
     * neither is client input. They are written only by
     * `spawnRecurrence()` and `RecurrenceService` respectively.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function recurrenceAttributes(array $data): array
    {
        $unit = $data['repeat_unit'] ?? null;

        if ($unit === null) {
            return ['repeat_unit' => null, 'repeat_interval' => null, 'repeat_until' => null];
        }

        return [
            'repeat_unit' => $unit,
            'repeat_interval' => (int) $data['repeat_interval'],
            'repeat_until' => Carbon::parse($data['repeat_until']),
        ];
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
     *
     * `$groupId` seeds `applyJitter()` for `type=schedule` only — see
     * that method's docblock. Never applied to `now` (the user is
     * watching; publishing early would violate `PublishService::
     * PUBLISH_DATE_GRACE_SECONDS`) or `draft` (no schedule commitment
     * exists yet to spread).
     */
    private function resolvePublishDate(string $type, ?string $publishDate, string $groupId): ?CarbonInterface
    {
        return match ($type) {
            'now' => Carbon::now(),
            'schedule' => $this->applyJitter(Carbon::parse($publishDate), $groupId),
            'draft' => $publishDate !== null ? Carbon::parse($publishDate) : Carbon::now(),
            default => null,
        };
    }

    /**
     * Durable-scheduling rewrite (Task 6): spreads `$date` within
     * `config('publishing.jitter_seconds')` seconds either side,
     * deterministically seeded from `$seed` — never `random_int()`, or
     * re-saving the same post (any edit that re-runs `resolvePublishDate()`
     * without changing the date) would walk the jittered instant a
     * little further every time. `crc32()`, not a cryptographic hash:
     * this only needs to decorrelate a thundering herd of tenants who
     * all picked the same round number (e.g. every "12:00" schedule
     * publishing in the same instant and exhausting a shared per-
     * provider rate limit for everyone at once), not to resist anyone
     * choosing their own id to land on a specific offset — there is
     * nothing to gain by doing so.
     *
     * `$seed` is always the group's own id, including for
     * `spawnRecurrence()` — safe there specifically because
     * `RecurrenceService::spawnNext()`'s `claim()` (a conditional UPDATE
     * on `repeat_spawned_at`) is taken BEFORE `spawnRecurrence()` is ever
     * called and never retried once claimed, so a successor's id/jitter
     * pair is computed exactly once, never recomputed across a redelivery
     * the way `create()`'s could be re-submitted by a client retry.
     */
    private function applyJitter(CarbonInterface $date, string $seed): CarbonInterface
    {
        $window = (int) config('publishing.jitter_seconds');

        if ($window <= 0) {
            return $date;
        }

        $offsetSeconds = (crc32($seed) % (2 * $window + 1)) - $window;

        return $date->copy()->addSeconds($offsetSeconds);
    }

    /**
     * Three transitions are ever requested here: DRAFT (stay/enter
     * draft), SCHEDULED (`type=schedule` — durable, waiting; nothing is
     * dispatched), and QUEUE (`type=now` — claimed and dispatched
     * immediately, see `claimAndDispatch()`). Moving OUT of DRAFT is
     * always allowed; moving INTO DRAFT is only a no-op (already DRAFT)
     * — `PostStateMachine` has no `*->DRAFT` entry other than
     * `DRAFT->DRAFT` (see its docblock: those simply aren't transitions
     * this product supports), so a request that would require one is
     * rejected here as a normal validation failure (422) rather than
     * reaching `PostStateMachine::transition()` and throwing
     * `InvalidStateTransition`, which is reserved for programming
     * errors, not user input.
     */
    private function resolveTargetState(PostState $current, string $type): PostState
    {
        if ($type === 'draft') {
            if ($current !== PostState::DRAFT) {
                throw ValidationException::withMessages([
                    'type' => ["Cannot move a post from {$current->value} back to draft."],
                ]);
            }

            return PostState::DRAFT;
        }

        return $this->targetStateForType($type);
    }

    /**
     * `create()`'s equivalent of `resolveTargetState()` — no existing
     * `$current` state to validate a `draft` request against (a brand
     * new group is always `DRAFT` already), so this is the strictly
     * simpler "what does this `type` mean" half, shared by both.
     */
    private function targetStateForType(string $type): PostState
    {
        return match ($type) {
            'draft' => PostState::DRAFT,
            'now' => PostState::QUEUE,
            default => PostState::SCHEDULED,
        };
    }

    /**
     * Dispatches `PublishPostJob` for `$group` iff it just entered
     * `QUEUE` — a no-op for `DRAFT` and, since the durable-scheduling
     * rewrite, for `SCHEDULED` too: a `type=schedule` save never reaches
     * `QUEUE` at all any more, and is durable the instant this method's
     * caller commits, with no dispatch of any kind — `DueDispatchService`
     * claims it later. This method exists only for the two cases that
     * genuinely need a job to exist RIGHT NOW: `type=now`
     * (create()/update()) and a manual retry — both transition the group
     * straight into `QUEUE` and expect it to actually start publishing
     * immediately, not wait for the next per-minute tick.
     *
     * No `->delay()`: unlike the pre-rewrite version of this method,
     * every call site here already means "this is due right now" — a
     * scheduled post for a future date never reaches this method as
     * `QUEUE` in the first place (see `resolveTargetState()`).
     *
     * Deliberately called AFTER the caller's own `DB::transaction()` has
     * fully committed, never from inside it. Two independent reasons,
     * both hard requirements:
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
     *    afterwards. Every existing Post CRUD test that publishes-now
     *    (CreatePostTest, UpdatePostTest, PostMediaTest) calls
     *    `Queue::fake()` for exactly this reason — a real dispatch() on
     *    the sync driver would otherwise run `PublishPostJob` for real,
     *    inline, in the middle of tests that have nothing to do with
     *    publishing. `tests/Feature/Publishing/PublishPostJobTest.php`
     *    is what exercises the job for real, by constructing and calling
     *    it directly rather than through dispatch().
     *
     * $forceRedispatch (post-review addition, finding 1 round 2, kept
     * through the durable-scheduling rewrite): force-releases
     * `PublishPostJob`'s `ShouldBeUnique` lock for `$group` immediately
     * before dispatching. `false` for `create()`: a brand-new group
     * cannot already hold a lock from an earlier dispatch for the SAME
     * group id (`uniqueId()` is the group's own freshly-minted ulid), so
     * there is nothing to release there and no reason to pay the extra
     * cache round-trip on the common path. `true` everywhere else this
     * is called (an existing group re-entering QUEUE): re-editing to
     * "publish now" a group that was already claimed once (or retrying
     * one whose previous attempt's lock has not yet expired) must not
     * silently drop, and releasing a lock that isn't held is a harmless
     * no-op either way (`Illuminate\Bus\UniqueLock::release()` ->
     * `Cache::lock(...)->forceRelease()`, which simply deletes a cache
     * key that may not exist).
     *
     * Safety: force-releasing this lock reopens exactly the race
     * `ShouldBeUnique` exists to prevent — a second dispatch for the
     * SAME group now can reach the queue while an earlier one is still
     * pending/executing. Two guards, both already in place, are what
     * keep that race from ever producing a duplicate publish: the due-
     * date/state check in `PostGroupLifecycle::claim()` (a stale
     * job — the one whose lock was just force-released — that pops LATE
     * simply no-ops instead of publishing early) and `publishChannel()`'s
     * own per-row `lockForUpdate()` + `release_id IS NULL` filter (even
     * two genuinely-concurrent runs of the SAME group can only ever
     * publish a given segment once — see `PublishService`'s class
     * docblock, idempotency point 4). Force-releasing without either of
     * those would have been unsafe; with both, the worst case is a stale
     * job that does nothing.
     *
     * `last_dispatched_at`/`dispatch_attempts`: stamped here, alongside
     * every other place a `PublishPostJob` is actually dispatched
     * (`DueDispatchService::claimBatch()`, `MissedPostsService::dispatchRedispatch()`)
     * — `MissedPostsService`'s stuck-row scan needs both to be accurate
     * for ANY dispatch, not only ones that went through the durable
     * per-minute claim. A plain column write, not folded into the
     * caller's own transaction: this method already runs deliberately
     * AFTER that transaction commits (see above), and the dispatch call
     * immediately below is about to succeed or no-op for reasons
     * entirely independent of this column, so there is nothing here
     * worth an extra `lockForUpdate()` round-trip over.
     */
    private function claimAndDispatch(PostGroup $group, bool $forceRedispatch = false): void
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
        PostGroup::query()->whereKey($group->id)->update([
            'last_dispatched_at' => now(),
            'dispatch_attempts' => DB::raw('dispatch_attempts + 1'),
        ]);

        if ($forceRedispatch) {
            app(UniqueLock::class)->release(new PublishPostJob($group->id, $group->queue_version));
        }

        PublishPostJob::dispatch($group->id, $group->queue_version);
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

        $this->assertOwnedByOrganization(Media::class, $mediaIds, $post->organization_id, 'media');

        $pivotRows = [];
        foreach ($mediaIds as $order => $mediaId) {
            $pivotRows[$mediaId] = ['order' => $order];
        }

        $post->media()->attach($pivotRows);
    }

    /**
     * The one place tags are written onto a group, so the ownership
     * assertion below has exactly one place to live rather than three.
     *
     * @param  list<string>|array<int, string>  $tagIds
     */
    private function syncTags(PostGroup $group, array $tagIds): void
    {
        $this->assertOwnedByOrganization(Tag::class, array_values($tagIds), $group->organization_id, 'tags');

        $group->tags()->sync($tagIds);
    }

    /**
     * Fails unless every one of `$ids` names a row of `$modelClass` owned
     * by `$organizationId`.
     *
     * Why this exists at all (final review, batch 4 — tenancy). Both
     * pivots this class writes — `media_post` and `post_group_tag` — are
     * written through a `BelongsToMany`, which takes ids and writes rows
     * without ever instantiating the related model. The `organization`
     * global scope is therefore never consulted, and neither pivot table
     * carries an `organization_id` column of its own to scope. The only
     * thing that has been keeping a foreign id out is that every caller
     * happens to be an HTTP request whose FormRequest already filtered
     * with `Rule::exists()->where('organization_id', …)` — a property of
     * where the guard sits, not of this code.
     *
     * The blast radius is not hypothetical: `PublishService` re-reads the
     * media pivot with `withoutGlobalScope('organization')` (it must —
     * the publish job runs with no tenant in context), so a foreign row
     * that got in here ends up uploaded to a social network.
     *
     * Deliberately checked against the OWNING ROW's `organization_id`
     * rather than `OrganizationContext`: this must hold for a caller with
     * no context set at all — a job, a console command, an importer —
     * which is precisely the caller the FormRequest does not protect.
     *
     * 404, not 403: an id from another tenant must not be distinguishable
     * from an id that does not exist, or this becomes an oracle for what
     * other organizations own.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $ids
     */
    private function assertOwnedByOrganization(string $modelClass, array $ids, string $organizationId, string $label): void
    {
        if ($ids === []) {
            return;
        }

        $owned = $modelClass::query()
            ->withoutGlobalScope('organization')
            ->whereIn('id', $ids)
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->all();

        $foreign = array_diff(array_unique($ids), $owned);

        if ($foreign !== []) {
            abort(404, "One or more {$label} do not exist.");
        }
    }
}
