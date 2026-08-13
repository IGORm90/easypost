<?php

namespace App\Http\Requests\Posts\Concerns;

use App\Models\Integration;
use App\Services\Posts\PostValidationService;
use App\Social\Dto\PostPayloadDto;
use App\Support\OrganizationContext;
use Illuminate\Support\Carbon;
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
 * What this trait deliberately does NOT check: whether a referenced
 * `integration_id` belongs to the CURRENT organization. `exists:
 * integrations,id` below matches the raw `integrations` table
 * (bypassing BelongsToOrganization's global scope, same as every other
 * Laravel `exists` rule in this codebase), so it only rules out
 * `integration_id`s that don't exist at all — anywhere, any tenant. A
 * value that exists but belongs to a different organization sails
 * through this class's rules() untouched and is instead rejected with
 * 403 by `PostGroupService` (`resolveIntegrations()`), never with 422 —
 * see the task-20 brief: "Cross-tenant integrations are 403, not 422."
 * `validateChannelLimits()` below mirrors that split: it silently skips
 * any `integration_id` it cannot resolve within the current tenant,
 * leaving that case entirely to the service.
 *
 * `media_ids`: shape-checked only (must be well-formed ULIDs), NOT
 * existence- or ownership-checked. There is no `media` table yet (Task
 * 28 owns it), so nothing here can confirm a given id refers to a real
 * attachment, let alone one belonging to this organization. Every
 * `media_ids` value that reaches PostGroupService, and everything read
 * back out of `settings->media_ids` by PostResource, must therefore be
 * treated as UNVERIFIED CLIENT INPUT — Task 28 must re-validate
 * existence and organization ownership before trusting any of it (e.g.
 * before joining on it, or before handing it to a provider upload
 * call). Scoping it to the current organization here is not possible
 * without the `Media` model/table to scope against.
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
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', Rule::exists('tags', 'id')->where('organization_id', OrganizationContext::id())],
            'posts' => ['required', 'array', 'min:1'],
            // 'distinct': one chain per integration per request — two
            // entries for the same channel would both try to root their
            // chain at order 0 and collide on
            // `posts_chain_order_unique`, which must surface as a clean
            // 422 here, not a raw QueryException from PostGroupService.
            'posts.*.integration_id' => ['required', 'string', 'distinct', Rule::exists('integrations', 'id')->whereNull('deleted_at')],
            'posts.*.settings' => ['sometimes', 'nullable', 'array'],
            'posts.*.value' => ['required', 'array', 'min:1'],
            'posts.*.value.*.content' => ['required', 'string'],
            'posts.*.value.*.media_ids' => ['sometimes', 'array'],
            // 'ulid': shape only — see this trait's docblock ("media_ids")
            // for why existence/ownership cannot be checked here.
            'posts.*.value.*.media_ids.*' => ['string', 'ulid'],
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
            $this->validateChannelLimits($validator);
        });
    }

    /**
     * `type=schedule` needs a future `publish_date` — a scheduled post
     * in the past can never be published. `type=now` ignores whatever
     * `publish_date` was sent (PostGroupService always stamps `now()`
     * itself — see its docblock); `type=draft` has no date requirement
     * at all, since a draft may sit on the calendar with no slot, an
     * arbitrary placeholder date, or none.
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
                fn (array $segment): PostPayloadDto => new PostPayloadDto(content: (string) ($segment['content'] ?? '')),
                (array) ($item['value'] ?? []),
            );

            foreach ($service->validate($integration, $chain) as $error) {
                $validator->errors()->add($this->fieldFor((int) $i, $error), $error['message']);
            }
        }
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
