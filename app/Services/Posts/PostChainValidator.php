<?php

namespace App\Services\Posts;

use App\Enums\RepeatUnit;
use App\Models\Integration;
use App\Models\Media;
use App\Social\Dto\MediaDto;
use App\Social\Dto\PostPayloadDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything about a post payload that Laravel's structural rules cannot say:
 * the publish date, the recurrence rule, and each channel's own limits (via
 * PostValidationService) together with the media the payload references. It takes
 * the payload array and returns the errors as an ordered list of
 * `{field, message}`, in the order they are found — date, then recurrence, then
 * the channels — so the request is only an adapter and the rules can be tested
 * without HTTP.
 *
 * It is called only once the structural rules have passed (shape, and ownership of
 * every id: integrations, media and tags are all scoped to the current
 * organization there), and trusts them. Integrations and media are looked up
 * through the 'organization' scope, so it must run for a signed-in tenant. An
 * integration it cannot resolve in this organization is skipped: the request's own
 * rules have already refused it.
 *
 * Publish time does NOT run this: content that was accepted when it was saved is
 * not re-checked when it is sent.
 */
class PostChainValidator
{
    public function __construct(private readonly PostValidationService $limits) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{field: string, message: string}>
     */
    public function validate(array $payload): array
    {
        $errors = [];

        $add = function (string $field, string $message) use (&$errors): void {
            $errors[] = ['field' => $field, 'message' => $message];
        };

        $this->validatePublishDate($payload, $add);
        $this->validateRecurrence($payload, $add);
        $this->validateChannelLimits($payload, $add);

        return $errors;
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
     *
     * @param  array<string, mixed>  $payload
     */
    private function validatePublishDate(array $payload, callable $add): void
    {
        if (($payload['type'] ?? null) !== 'schedule') {
            return;
        }

        if (! filled($payload['publish_date'] ?? null)) {
            $add('publish_date', 'The publish date is required when scheduling a post.');

            return;
        }

        $date = Carbon::parse($payload['publish_date']);

        if ($date->isPast()) {
            $add('publish_date', 'The publish date must be in the future.');
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
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateRecurrence(array $payload, callable $add): void
    {
        $unit = ($payload['repeat_unit'] ?? null);

        if (! is_string($unit) || RepeatUnit::tryFrom($unit) === null) {
            return;
        }

        $interval = (int) ($payload['repeat_interval'] ?? 0);
        $max = RepeatUnit::from($unit)->maxInterval();

        if ($interval > $max) {
            $add('repeat_interval', "The repeat interval may not be greater than {$max} for this unit.");
        }

        if (! filled($payload['repeat_until'] ?? null)) {
            return;
        }

        $until = Carbon::parse($payload['repeat_until']);

        if ($until->isPast()) {
            $add('repeat_until', 'The repeat end date must be in the future.');
        }

        if ($until->greaterThan(Carbon::now()->addYear())) {
            $add('repeat_until', 'The repeat end date may not be more than a year away.');
        }

        if (filled($payload['publish_date'] ?? null) && $until->lessThanOrEqualTo(Carbon::parse($payload['publish_date']))) {
            $add('repeat_until', 'The repeat end date must be after the publish date.');
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
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateChannelLimits(array $payload, callable $add): void
    {
        $mediaById = $this->resolveReferencedMedia($payload);

        foreach ((array) ($payload['posts'] ?? []) as $i => $item) {
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

            foreach ($this->limits->validate($integration, $chain) as $error) {
                $add($this->fieldFor((int) $i, $error), $error['message']);
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
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<string, Media>
     */
    private function resolveReferencedMedia(array $payload): Collection
    {
        $ids = [];

        foreach ((array) ($payload['posts'] ?? []) as $item) {
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
