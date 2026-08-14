<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Models\PostGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The entire public contract of GET /api/public/posts/{token} (Task 22).
 * This class is the single place that decides what an anonymous, no-auth
 * caller is allowed to see about a post group, so — per the brief —
 * every field below is picked deliberately, and nothing is delegated to
 * a broader resource (PostGroupResource/PostResource) that a later task
 * could silently grow with an internal field:
 *
 *   - no ids at all (group, post or integration) — an id is either an
 *     internal identifier with no display purpose here, or a potential
 *     key for probing another endpoint;
 *   - no `organization_id` — the tenant this content belongs to is not
 *     the public viewer's business;
 *   - no comments — internal team discussion, excluded entirely, not
 *     merely unlinked;
 *   - no `Integration::$token`/`refresh_token` — `IntegrationResource`
 *     is never used here for exactly this reason; only the three
 *     `Integration` columns a preview actually needs (provider
 *     identifier, display name, picture) are read directly off the
 *     model below.
 *
 * Keep this page simple, per the brief: Task 26 owns the real
 * per-network preview rendering (character-accurate previews, media,
 * thread layout beyond a flat content list) — `channels[].content` here
 * is deliberately just the ordered list of segment text for each
 * channel's thread, the minimum a "preview" needs until that task
 * builds the real thing. Whoever picks up Task 26 slots richer
 * per-channel rendering in here (or in SharedPost.vue, which consumes
 * this exact shape) without changing what this class is allowed to
 * expose.
 *
 * @mixin PostGroup
 */
class PublicPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Post> $posts */
        $posts = $this->posts;
        /** @var PostGroup $group */
        $group = $this->resource;

        return [
            'publish_date' => $this->publish_date,
            /*
             * Final review, Minor: the page rendered this date in the
             * VIEWER's timezone, on the one screen whose whole purpose is
             * telling a client when something goes out. `publish_date` is
             * UTC (every timestamp here is `timestampTz` in UTC), so the
             * viewer needs the zone it was scheduled in to read it. There
             * is no organization-level timezone column; the scheduler's
             * own profile timezone is the zone the calendar grid and the
             * editor already render every entry in, so it is the one this
             * date means. It exposes no more about the author than the
             * publish time already does, and never the author themselves.
             *
             * Gated on the nullable `created_by` column rather than on the
             * relation: `nullOnDelete()` means a group outlives its author,
             * and a group with no author left has no zone to report.
             */
            'timezone' => $group->created_by !== null ? $group->creator->timezone : 'UTC',
            'channels' => $posts->groupBy('integration_id')->map(function (Collection $chain): array {
                $chain = $chain->sortBy('order')->values();

                /** @var Post $root */
                $root = $chain->first();
                $integration = $root->integration;

                return [
                    'provider_identifier' => $integration->provider_identifier,
                    'name' => $integration->name,
                    'picture' => $integration->picture,
                    'content' => $chain->pluck('content')->values(),
                ];
            })->values(),
        ];
    }
}
