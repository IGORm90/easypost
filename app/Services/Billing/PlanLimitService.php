<?php

namespace App\Services\Billing;

use App\Enums\PlanLimit;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlanUsage;
use App\Services\Billing\Exceptions\PlanLimitExceededException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The single place this application asks "does this organization have
 * room for one more X" and, for the one metric that needs it, reserves
 * that room. Every future enforcement point (Task 36's middleware, and
 * `usage()`'s consumer, the plan/limits panel) must go through here —
 * never re-derive a count independently, or two implementations of "how
 * many channels does this org have" will eventually disagree.
 *
 * Counting decision, per metric (the three `PlanLimit` cases):
 *
 * - `CHANNELS`: counted on demand from `integrations` (live rows only —
 *   `Integration` is soft-deleting, and a plain query already excludes
 *   trashed rows). No cache: this table is small per organization and
 *   already indexed by `organization_id`, so a live count is cheap and
 *   can never drift from reality the way a cached counter could.
 * - `MEDIA_STORAGE_MB`: counted on demand as `SUM(media.size_bytes)` for
 *   the organization, converted to whole megabytes (rounded up, so a
 *   single byte over a boundary still reads as consuming that MB rather
 *   than silently rounding down). Sums the ORIGINAL file only —
 *   `size_bytes` is the only size this application ever records (see
 *   `Media`'s migration); a generated thumbnail's bytes are not counted
 *   towards this limit at all, since no column anywhere stores a
 *   thumbnail's size. If that ever needs to change, it needs a new
 *   column captured at generation time (`MediaUploadService::
 *   maybeStoreThumbnail()`), not a guess derived here.
 * - `POSTS_PER_MONTH`: the one metric that is genuinely a *rate* (bounded
 *   per calendar month), not a point-in-time count of live rows, so it is
 *   cached in `plan_usages` and incremented as part of `check()` itself —
 *   see `claimPosts()`. `usage()` reads the exact same cached row
 *   read-only, so the two never compute this figure differently.
 *
 * Concurrency: `CHANNELS`/`MEDIA_STORAGE_MB` are plain reads here — this
 * class does not (and, being a pure "may I" check with the actual create
 * happening in a separate call from a separate class, cannot alone)
 * close a TOCTOU race for those two; that requires the future caller
 * (Task 36) to serialize check-then-create the way `PublishService`/
 * `SignatureService` already do elsewhere in this codebase.
 * `POSTS_PER_MONTH` is different: because the "reservation" (the
 * `plan_usages.count` increment) happens INSIDE `check()` itself, this
 * class closes that race completely on its own — see `claimPosts()`.
 */
class PlanLimitService
{
    public function __construct(private readonly PlanRegistry $registry) {}

    /**
     * Throws `PlanLimitExceededException` if consuming `$delta` more of
     * `$limit` would put the organization over its plan's configured
     * maximum. A `null` maximum (see `config/plans.php`) always passes.
     *
     * For `POSTS_PER_MONTH` this call is not read-only: passing the check
     * atomically reserves `$delta` against the current UTC month's
     * counter (see `claimPosts()`) — call this exactly once per action
     * that should count, never speculatively.
     */
    public function check(Organization $organization, PlanLimit $limit, int $delta = 1): void
    {
        $max = $this->maxFor($organization, $limit);

        match ($limit) {
            PlanLimit::CHANNELS => $this->guard($limit, $this->channelCount($organization), $delta, $max),
            PlanLimit::MEDIA_STORAGE_MB => $this->guard($limit, $this->mediaStorageMb($organization), $delta, $max),
            PlanLimit::POSTS_PER_MONTH => $this->claimPosts($organization, $delta, $max),
        };
    }

    /**
     * Current usage against every `PlanLimit`, for the plan/limits panel.
     * Deliberately built from the exact same per-metric methods `check()`
     * uses (`channelCount()`, `mediaStorageMb()`, and — for posts — the
     * same cached `plan_usages` row, read here without claiming anything)
     * so this can never disagree with what `check()` would decide.
     *
     * @return array<value-of<PlanLimit>, array{current: int, max: int|null}>
     */
    public function usage(Organization $organization): array
    {
        $limits = $this->registry->forOrganization($organization)['limits'];

        return [
            PlanLimit::CHANNELS->value => [
                'current' => $this->channelCount($organization),
                'max' => $limits[PlanLimit::CHANNELS->value],
            ],
            PlanLimit::POSTS_PER_MONTH->value => [
                'current' => $this->currentPostUsage($organization),
                'max' => $limits[PlanLimit::POSTS_PER_MONTH->value],
            ],
            PlanLimit::MEDIA_STORAGE_MB->value => [
                'current' => $this->mediaStorageMb($organization),
                'max' => $limits[PlanLimit::MEDIA_STORAGE_MB->value],
            ],
        ];
    }

    private function maxFor(Organization $organization, PlanLimit $limit): ?int
    {
        return $this->registry->forOrganization($organization)['limits'][$limit->value];
    }

    private function guard(PlanLimit $limit, int $current, int $delta, ?int $max): void
    {
        if ($max !== null && $current + $delta > $max) {
            throw new PlanLimitExceededException($limit, $current, $max);
        }
    }

    /**
     * Live (non-soft-deleted) integration count. `withoutGlobalScope
     * ('organization')` by name (never the blanket `withoutGlobalScopes()`
     * — this codebase's established convention, see e.g.
     * `TokenRefreshService`), because this must give the right answer
     * for `$organization` regardless of the ambient `OrganizationContext`
     * — a console command or job calling this has none.
     */
    private function channelCount(Organization $organization): int
    {
        return Integration::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->count();
    }

    /**
     * Whole megabytes, rounded up — see class docblock for why only
     * `size_bytes` (the original file) is summed.
     */
    private function mediaStorageMb(Organization $organization): int
    {
        $bytes = (int) Media::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->sum('size_bytes');

        return (int) ceil($bytes / (1024 * 1024));
    }

    private function currentPeriod(): string
    {
        return Date::now('UTC')->format('Y-m');
    }

    /**
     * Read-only: the current UTC month's cached post count, or 0 if no
     * row exists yet for this organization/period. Shared by `usage()`
     * and (before it claims anything) `claimPosts()` below — both read
     * "current" the same way.
     */
    private function currentPostUsage(Organization $organization): int
    {
        return (int) (PlanUsage::query()
            ->where('organization_id', $organization->id)
            ->where('metric', PlanLimit::POSTS_PER_MONTH->value)
            ->where('period', $this->currentPeriod())
            ->value('count') ?? 0);
    }

    /**
     * Atomically reserves `$delta` of this UTC month's post budget for
     * `$organization`, throwing instead of reserving if that would
     * exceed `$max`.
     *
     * Locks the `organizations` row (guaranteed to already exist, unlike
     * any `plan_usages` row, which may not exist yet for this org/period)
     * for the duration of a `DB::transaction()` — the exact pattern
     * `SignatureService::clearDefault()` and `MembershipService::
     * guardLastSuperadmin()` already use in this codebase to serialize
     * concurrent writers around a row that's guaranteed to be there. A
     * second, genuinely concurrent `check()` call for the same
     * organization blocks on that lock until the first transaction
     * commits (reserving its `$delta`) or rolls back (reserving
     * nothing), then re-reads the counter as it now stands — so two
     * requests racing to create the last post a `posts_per_month: 1`
     * plan allows can never both pass. Proven with `pcntl_fork()` in
     * `PlanLimitServiceTest` (sequential Pest calls cannot reproduce this
     * class of bug — each call's transaction fully commits before the
     * next one starts).
     *
     * The `plan_usages` unique index (`organization_id`, `metric`,
     * `period`) is a backstop for this lock, not the mechanism — same
     * relationship as the partial unique index behind
     * `SignatureService`'s default-clearing.
     */
    private function claimPosts(Organization $organization, int $delta, ?int $max): void
    {
        DB::transaction(function () use ($organization, $delta, $max): void {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $period = $this->currentPeriod();

            $row = PlanUsage::query()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'metric' => PlanLimit::POSTS_PER_MONTH->value,
                    'period' => $period,
                ],
                ['count' => 0],
            );

            $current = $row->count;

            if ($max !== null && $current + $delta > $max) {
                throw new PlanLimitExceededException(PlanLimit::POSTS_PER_MONTH, $current, $max);
            }

            $row->increment('count', $delta);
        });
    }
}
