<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Post;
use App\Models\PostGroup;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PostGroup
    {
        return DB::transaction(function () use ($data) {
            $this->resolveIntegrations($data['posts']);

            $targetState = $data['type'] === 'draft' ? PostState::DRAFT : PostState::QUEUE;

            $group = PostGroup::create([
                'state' => PostState::DRAFT,
                'publish_date' => $this->resolvePublishDate($data['type'], $data['publish_date'] ?? null),
            ]);

            if ($targetState !== PostState::DRAFT) {
                $this->stateMachine->transition($group, $targetState);
            }

            $this->replaceChains($group, $data['posts'], $targetState);

            if (array_key_exists('tags', $data)) {
                $group->tags()->sync($data['tags'] ?? []);
            }

            return $group->fresh(['posts.media', 'tags']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PostGroup $group, array $data): PostGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $this->guardEditable($group);
            $this->resolveIntegrations($data['posts']);

            $targetState = $this->resolveTargetState($group->state, $data['type']);

            // Full replace: every existing row is dropped first. Safe
            // only because guardEditable() above already proved none of
            // them carries a release_id — see this class's docblock.
            $group->posts()->delete();

            $group->publish_date = $this->resolvePublishDate($data['type'], $data['publish_date'] ?? null);
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
    }

    /**
     * Copies `$source`'s chains verbatim into a brand-new DRAFT group:
     * fresh ids throughout, and none of the publish history
     * (`release_id`/`release_url`/`error`/`published_at`) — a duplicate
     * is new, unpublished content, never a second claim on an existing
     * release.
     */
    public function duplicate(PostGroup $source): PostGroup
    {
        return DB::transaction(function () use ($source) {
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

    public function updateDate(PostGroup $group, CarbonInterface $publishDate): PostGroup
    {
        $this->guardEditable($group);

        $group->publish_date = $publishDate;
        $group->save();

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
        $this->guardEditable($group);

        $group->delete();
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
     * A group may be replaced or deleted only while every one of its
     * segments has never been released — see this class's docblock.
     */
    private function guardEditable(PostGroup $group): void
    {
        if ($group->state === PostState::PUBLISHED || $group->posts()->whereNotNull('release_id')->exists()) {
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
