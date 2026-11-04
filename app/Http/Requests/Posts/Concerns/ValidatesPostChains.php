<?php

namespace App\Http\Requests\Posts\Concerns;

use App\Enums\RepeatUnit;
use App\Models\Integration;
use App\Models\Media;
use App\Services\Posts\PostValidationService;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared shape/semantics for the POST /api/posts and PUT
 * /api/posts/{group} request bodies — both carry the exact payload
 * described in the task-20 brief (`type`, `publish_date`, `tags`,
 * `posts[].{integration_id,settings,value[]}`), so `StorePostRequest`
 * and `UpdatePostRequest` share this trait instead of duplicating the
 * rule set and the semantic checks below it.
 *
 * Two kinds of failure live here, deliberately kept apart:
 *
 *  - Structural failures (missing/wrong-typed fields, an
 *    `integration_id` that doesn't exist ANYWHERE) are plain Laravel
 *    rules() entries — Laravel's own machinery turns those into the
 *    standard `errors` shape.
 *  - Semantic failures that need the *parsed* payload (a past
 *    `publish_date`, a segment that breaks a provider's limits) run in
 *    `withValidator()`'s `after()` hook, and ONLY when the structural
 *    rules already passed — running PostValidationService against a
 *    malformed chain (e.g. a missing `value` array) would either crash
 *    or produce meaningless errors.
 *
 * Integration ownership IS checked here, with the same scoped
 * `Rule::exists(...)->where('organization_id', ...)` the other ids use.
 * An `integration_id` that does not exist and one that belongs to ANOTHER
 * organization are refused identically — a 422 on `posts.N.integration_id`
 * with the same message — so the response never tells a caller whether an
 * integration id exists in some other organization. (This used to be an
 * unscoped rule plus a 403 from `PostGroupService::resolveIntegrations()`,
 * which did exactly that: a missing id was a 422 and a foreign one a 403.)
 * `resolveIntegrations()` stays as a backstop for a caller that does not go
 * through this request. `validateChannelLimits()` below silently skips any
 * `integration_id` it cannot resolve within the current tenant, since this
 * rule has already refused it.
 *
 * `media_ids` (Task 28): shape (`ulid`) AND existence/ownership are both
 * checked right here, same pattern StoreSetRequest uses for
 * `integration_ids` — `Rule::exists('media', 'id')->where('organization_id',
 * ...)` rules out any id that doesn't exist at all, or exists but
 * belongs to another organization, as a normal 422 before this class's
 * `after()` hook (and, downstream, PostGroupService) ever sees it. This
 * closes the gap the task-20/23 review left open: an unscoped
 * `exists:media,id` (the pattern used for `integration_id` above,
 * deliberately deferred to a 403 in the service layer) would let a
 * cross-tenant media id resolve into a real, private file being
 * attached to this organization's post with no error at all.
 *
 * `validateChannelLimits()` below resolves the same scoped `Media` rows
 * a second time (once the structural rules above have already proven
 * every id is real and owned) to build actual `MediaDto`s for
 * PostValidationService — the only way its `maxImages`/`maxVideos`/
 * `allowedMimeTypes`/`maxFileSizeBytes`/`requiresAltText` checks become
 * reachable in practice rather than permanently validating an empty
 * `media: []` array.
 */
trait ValidatesPostChains
{
    /**
     * @return array<string, mixed>
     */
    protected function chainRules(): array
    {
        return [
            'type' => ['required', Rule::in(['schedule', 'draft', 'now'])],
            'publish_date' => ['nullable', 'date'],
            // Block 4. The recurrence rule rides on the same body as the
            // post: `repeat_unit` alone answers "does this repeat?", and
            // the other two are meaningless without it, hence
            // `required_with` in both directions. The interval's upper
            // bound depends on the unit and therefore lives in
            // validateRecurrence() below, not here.
            'repeat_unit' => ['nullable', Rule::enum(RepeatUnit::class), 'required_with:repeat_interval,repeat_until'],
            'repeat_interval' => ['nullable', 'integer', 'min:1', 'required_with:repeat_unit'],
            'repeat_until' => ['nullable', 'date', 'required_with:repeat_unit'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', Rule::exists('tags', 'id')->where('organization_id', OrganizationContext::id())],
            'posts' => ['required', 'array', 'min:1'],
            // 'distinct': one chain per integration per request — two
            // entries for the same channel would both try to root their
            // chain at order 0 and collide on
            // `posts_chain_order_unique`, which must surface as a clean
            // 422 here, not a raw QueryException from PostGroupService.
            'posts.*.integration_id' => ['required', 'string', 'distinct', Rule::exists('integrations', 'id')->where('organization_id', OrganizationContext::id())->whereNull('deleted_at')],
            'posts.*.settings' => ['sometimes', 'nullable', 'array'],
            'posts.*.value' => ['required', 'array', 'min:1'],
            'posts.*.value.*.content' => ['required', 'string'],
            // 'list': review Finding 1 — an object-shaped media_ids
            // (`{"a": "<ulid>"}`) previously passed 'array' alone, then
            // reached PostGroupService::attachMedia() with a non-integer
            // array key used directly as the pivot's `order` value,
            // producing an uncaught QueryException instead of a 422 (the
            // unsigned-integer `order` column rejects a string like
            // 'a'). 'list' rejects any non-sequential-integer-keyed
            // input here, which is also what makes this class's own
            // `@param list<string>` annotations on attachMedia() and
            // friends actually true rather than merely hoped for.
            // Review round 2 (Important, regression the coordinator
            // introduced and owned): Laravel's built-in 'distinct' rule
            // does NOT scope to the nearest array — extractDistinctValues()
            // works from getLeadingExplicitAttributePath(), which for
            // `posts.*.value.*.media_ids.*` is `posts` (the very first
            // segment, before any wildcard), not `media_ids`. That made
            // `distinct` compare EVERY media_ids.* value across the WHOLE
            // request, so the same image reused across two segments of a
            // thread, or across two different channels in one request —
            // the entire reason a shared media library exists — 422'd
            // even though `media_post` is unique per (media_id, post_id)
            // and two distinct Post rows collide on nothing.
            // `posts.*.integration_id`'s own `distinct` above only
            // happened to work because it has a single wildcard, so its
            // leading path coincidentally equals the intended scope.
            // Fixed with a plain Closure rule instead: Laravel invokes it
            // once per concrete `posts.{i}.value.{j}.media_ids` path, so
            // $value is genuinely just that one segment's own array —
            // exactly the "duplicate within one segment" check the
            // original Minor 2 finding asked for, with no
            // cross-request/cross-segment/cross-channel bleed.
            'posts.*.value.*.media_ids' => ['sometimes', 'array', 'list', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }

                if (count($value) !== count(array_unique($value))) {
                    $fail('The :attribute field has a duplicate value.');
                }
            }],
            // ulid shape + existence/ownership, both enforced here — see
            // this trait's docblock ("media_ids").
            'posts.*.value.*.media_ids.*' => [
                'string',
                'ulid',
                Rule::exists('media', 'id')->where('organization_id', OrganizationContext::id()),
            ],
        ];
    }

    protected function applyChainValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Semantic checks assume a structurally valid payload — see
            // this trait's docblock for why they're skipped otherwise.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validatePublishDate($validator);
            $this->validateRecurrence($validator);
            $this->validateChannelLimits($validator);
        });
    }

    /**
     * `type=schedule` needs a future `publish_date` — a scheduled post
     * in the past can never be published. `type=now` ignores whatever
     * `publish_date` was sent (PostGroupService always stamps `now()`
     * itself — see its docblock); `type=draft` has no date requirement
     * at all — a caller may give it an arbitrary placeholder date, or
     * none. When none is given, `PostGroupService::resolvePublishDate()`
     * fills in `now()` itself (not this class's job — validation only
     * decides whether a value is *required*, never what a missing one
     * defaults to), so the draft still lands on today's `GET
     * /api/calendar` instead of being unfindable.
     */
    private function validatePublishDate(Validator $validator): void
    {
        if ($this->input('type') !== 'schedule') {
            return;
        }

        if (! $this->filled('publish_date')) {
            $validator->errors()->add('publish_date', 'The publish date is required when scheduling a post.');

            return;
        }

        $date = Carbon::parse($this->input('publish_date'));

        if ($date->isPast()) {
            $validator->errors()->add('publish_date', 'The publish date must be in the future.');
        }
    }

    /**
     * The three recurrence rules that need the parsed payload rather
     * than a single field.
     *
     * `repeat_interval`'s ceiling is a property of the unit
     * (`RepeatUnit::maxInterval()`) — 365 days, 52 weeks or 12 months —
     * so it cannot be a static `max:` rule without hard-coding the same
     * table in a third place.
     *
     * `repeat_until` is bounded on BOTH sides. In the past, a series
     * would end before it began. Further out than a year, it is a
     * commitment nobody is watching — every occurrence is a real post
     * against the monthly quota, and the block's whole "no endless
     * series" rule would be defeated by simply typing a distant year.
     * And it must be strictly after `publish_date`, or the very first
     * successor is already past the end.
     */
    private function validateRecurrence(Validator $validator): void
    {
        $unit = $this->input('repeat_unit');

        if (! is_string($unit) || RepeatUnit::tryFrom($unit) === null) {
            return;
        }

        $interval = (int) $this->input('repeat_interval');
        $max = RepeatUnit::from($unit)->maxInterval();

        if ($interval > $max) {
            $validator->errors()->add('repeat_interval', "The repeat interval may not be greater than {$max} for this unit.");
        }

        if (! $this->filled('repeat_until')) {
            return;
        }

        $until = Carbon::parse($this->input('repeat_until'));

        if ($until->isPast()) {
            $validator->errors()->add('repeat_until', 'The repeat end date must be in the future.');
        }

        if ($until->greaterThan(Carbon::now()->addYear())) {
            $validator->errors()->add('repeat_until', 'The repeat end date may not be more than a year away.');
        }

        if ($this->filled('publish_date') && $until->lessThanOrEqualTo(Carbon::parse($this->input('publish_date')))) {
            $validator->errors()->add('repeat_until', 'The repeat end date must be after the publish date.');
        }
    }

    /**
     * Runs every resolvable chain through PostValidationService and
     * maps each returned error onto the field path of the segment it
     * came from, so the frontend can highlight the exact offending
     * input. `post_index` (the segment's position within one
     * integration's `value` array) becomes `posts.{i}.value.{post_index}`;
     * content-shaped codes (`content_too_long`, `empty_content`) get a
     * `.content` suffix, media-shaped codes get a `.media_ids` suffix,
     * and chain-shape codes (`threads_not_supported`,
     * `too_many_segments`) are attached to the segment itself with no
     * suffix, since they describe the segment's mere presence in the
     * chain rather than one of its fields.
     */
    private function validateChannelLimits(Validator $validator): void
    {
        /** @var PostValidationService $service */
        $service = app(PostValidationService::class);
        $mediaById = $this->resolveReferencedMedia();

        foreach ((array) $this->input('posts', []) as $i => $item) {
            $integrationId = $item['integration_id'] ?? null;

            if (! is_string($integrationId)) {
                continue;
            }

            // Org-scoped on purpose — see this trait's docblock. A
            // cross-tenant id resolves to null here and is left for
            // PostGroupService to reject with 403.
            $integration = Integration::query()->find($integrationId);

            if ($integration === null) {
                continue;
            }

            $chain = array_map(
                fn (array $segment): PostPayloadDto => new PostPayloadDto(
                    content: (string) ($segment['content'] ?? ''),
                    media: $this->resolveSegmentMedia($segment, $mediaById),
                ),
                (array) ($item['value'] ?? []),
            );

            foreach ($service->validate($integration, $chain) as $error) {
                $validator->errors()->add($this->fieldFor((int) $i, $error), $error['message']);
            }
        }
    }

    /**
     * Every `media_ids` value referenced anywhere in the payload,
     * resolved to its `Media` row in one query — scoped to the current
     * organization by `Media`'s own `BelongsToOrganization` global
     * scope, same as every other org-scoped lookup in this codebase. By
     * the time this runs, the `exists`-with-organization-scope rule in
     * `chainRules()` has already proven every id here both exists and
     * belongs to this organization (see `applyChainValidation()`'s
     * early return on structural errors), so this is a plain lookup,
     * not a second ownership check.
     *
     * @return Collection<string, Media>
     */
    private function resolveReferencedMedia(): Collection
    {
        $ids = [];

        foreach ((array) $this->input('posts', []) as $item) {
            foreach ((array) ($item['value'] ?? []) as $segment) {
                foreach ((array) ($segment['media_ids'] ?? []) as $id) {
                    if (is_string($id)) {
                        $ids[] = $id;
                    }
                }
            }
        }

        if ($ids === []) {
            return collect();
        }

        return Media::query()->whereIn('id', array_unique($ids))->get()->keyBy('id');
    }

    /**
     * @param  array{media_ids?: list<string>}  $segment
     * @param  Collection<string, Media>  $mediaById
     * @return list<MediaDto>
     */
    private function resolveSegmentMedia(array $segment, Collection $mediaById): array
    {
        $media = [];

        foreach ((array) ($segment['media_ids'] ?? []) as $id) {
            $row = $mediaById->get($id);

            if ($row instanceof Media) {
                // Media::toDto() is the only place that constructs a
                // MediaDto from a stored row — $sizeBytes/$mimeType/$path
                // come straight off the persisted, upload-time-captured
                // columns, never re-derived from disk. See that method's
                // docblock.
                $media[] = $row->toDto();
            }
        }

        return $media;
    }

    /**
     * @param  array{post_index: int, code: string, message: string, limit: int|string|null, actual: int|string|null}  $error
     */
    private function fieldFor(int $postsIndex, array $error): string
    {
        $base = "posts.{$postsIndex}.value.{$error['post_index']}";

        $contentCodes = ['content_too_long', 'empty_content'];
        $mediaCodes = [
            'too_many_images', 'too_many_videos', 'mixed_media_not_supported',
            'unsupported_mime', 'file_too_large', 'file_size_unknown', 'missing_alt_text',
        ];

        return match (true) {
            in_array($error['code'], $contentCodes, true) => "{$base}.content",
            in_array($error['code'], $mediaCodes, true) => "{$base}.media_ids",
            default => $base,
        };
    }
}
