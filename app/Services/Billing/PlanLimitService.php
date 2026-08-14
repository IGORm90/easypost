<?php

namespace App\Services\Billing;

use App\Enums\PlanLimit;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Services\Billing\Exceptions\PlanLimitExceededException;
use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place this application asks "does this organization have
 * room for one more X". Every future enforcement point (Task 36's
 * middleware/controllers, and `usage()`'s consumer, the plan/limits panel)
 * must go through here — never re-derive a count independently, or two
 * implementations of "how many channels does this org have" will
 * eventually disagree.
 *
 * Counting decision, per metric (the three `PlanLimit` cases): all three
 * are counted ON DEMAND, always, from the real rows that back them —
 * `integrations`, `media`, and `post_groups` respectively. There is no
 * cached counter anywhere in this class, on purpose:
 *
 * - `CHANNELS`: `COUNT(*)` over live (non-soft-deleted — `Integration` is
 *   soft-deleting, and a plain query already excludes trashed rows)
 *   `integrations` for the organization.
 * - `MEDIA_STORAGE_MB`: `SUM(media.size_bytes)` for the organization, kept
 *   in bytes for every comparison and converted to whole megabytes
 *   (rounded up) only once, for display in `usage()`/the exception body.
 *   Rounding on every intermediate step (the pre-fix behaviour) silently
 *   inflates totals — two 0.4 MB files would read as 1 MB + 1 MB = 2 MB
 *   instead of the correct ceil(0.8 MB) = 1 MB. Sums the ORIGINAL file
 *   only — `size_bytes` is the only size this application ever records
 *   (see `Media`'s migration); a generated thumbnail's bytes are not
 *   counted towards this limit at all, since no column anywhere stores a
 *   thumbnail's size. If that ever needs to change, it needs a new column
 *   captured at generation time (`MediaUploadService::
 *   maybeStoreThumbnail()`), not a guess derived here.
 * - `POSTS_PER_MONTH`: `COUNT(*)` over `post_groups` for the organization
 *   with `created_at` inside the current UTC calendar month
 *   (`[startOfMonth, startOfMonth + 1 month)`).
 *
 * This class previously cached `POSTS_PER_MONTH` in a `plan_usages` table,
 * incrementing it as part of `check()` itself. Review round 2 correctly
 * rejected that design: a counter that only ever grows, with no release
 * path, fails in exactly the direction that hurts customers — thirty
 * failed/abandoned `check()` calls (a validation error, an authorization
 * failure, a request nobody ever committed) would have permanently
 * reserved thirty posts' worth of quota with zero real posts to show for
 * it, recoverable only by hand-editing the database. On-demand counting
 * from `post_groups` cannot drift from reality by construction: whatever
 * is in that table right now, for this organization, this UTC month, IS
 * the current usage — nothing to reconcile, nothing to release, no
 * separate row that could ever disagree with it.
 *
 * Accepted trade-off, stated rather than hidden: `posts_per_month` is
 * therefore a STOCK (how many post_groups currently exist created this
 * month), not a true RATE (how many were ever created this month,
 * including ones later deleted). Deleting a post now frees quota for that
 * month — a customer could game the limit by deleting drafts and
 * recreating posts. That is a far smaller problem than a customer locked
 * out at zero real posts with no way back in short of manual database
 * surgery, which is what the increment-only design risked. If this needs
 * to become a true rate later, it needs an audit/event log to count
 * against instead of the live `post_groups` table — not a `plan_usages`
 * counter with no release path.
 *
 * Concurrency: neither `check()` alone can safely guard a create — the
 * actual resource creation (an `Integration` row, a `PostGroup` row, a
 * `Media` row) happens in a different call, and a plain "check, then
 * separately create" always has a TOCTOU gap regardless of how "current"
 * is computed. `withLimit()` closes that gap for any metric: it locks the
 * `organizations` row (the `SignatureService`/`MembershipService` pattern
 * already established in this codebase), re-counts under that lock, and
 * runs the caller's creation closure inside the same transaction before
 * releasing it. A second, genuinely concurrent `withLimit()` call for the
 * same organization blocks until the first transaction commits or rolls
 * back, then re-counts as it now stands — so two requests racing to
 * create the last unit a limit of one allows can never both succeed.
 * Proven with `pcntl_fork()` in `PlanLimitServiceTest`, for both
 * `POSTS_PER_MONTH` and `CHANNELS`.
 *
 * `check()` without `withLimit()` remains useful as a fast, non-locking
 * pre-check (e.g. a middleware wanting to return a friendly 402 before
 * even reaching the controller) — it is not itself the enforcement point;
 * the real guarantee is `withLimit()` wrapped around the actual create.
 */
class PlanLimitService
{
    public function __construct(private readonly PlanRegistry $registry) {}

    /**
     * Throws `PlanLimitExceededException` if consuming `$delta` more of
     * `$limit` would put the organization over its plan's configured
     * maximum. A `null` maximum (see `config/plans.php`) always passes,
     * without ever counting current usage. Read-only — does not reserve
     * or persist anything; see the class docblock for why real
     * enforcement needs `withLimit()`, not this alone.
     *
     * `$delta` must be at least 1. For `MEDIA_STORAGE_MB` specifically,
     * `$delta` is BYTES, not megabytes — see the class docblock for why
     * mixing already-rounded megabytes into this comparison is wrong.
     */
    public function check(Organization $organization, PlanLimit $limit, int $delta = 1): void
    {
        $this->guardDelta($delta);

        $max = $this->maxFor($organization, $limit);

        if ($max === null) {
            return;
        }

        $this->assertWithinLimit($limit, $this->currentUsage($organization, $limit), $delta, $this->toComparisonUnit($limit, $max));
    }

    /**
     * The atomic, safe-to-enforce-with version of `check()`: locks the
     * organization row, re-verifies `$limit` has room for `$delta` under
     * that lock, and — only if it does — runs `$create` (expected to
     * perform the actual INSERT) before the transaction commits and the
     * lock releases. Returns whatever `$create` returns.
     *
     * Throws `PlanLimitExceededException` (from inside the transaction,
     * before `$create` ever runs) exactly like `check()` — the closure is
     * never invoked if the limit would be exceeded, and if `$create`
     * itself throws, its INSERT never commits.
     */
    public function withLimit(Organization $organization, PlanLimit $limit, int $delta, Closure $create): mixed
    {
        $this->guardDelta($delta);

        return DB::transaction(function () use ($organization, $limit, $delta, $create): mixed {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $max = $this->maxFor($organization, $limit);

            if ($max !== null) {
                $this->assertWithinLimit($limit, $this->currentUsage($organization, $limit), $delta, $this->toComparisonUnit($limit, $max));
            }

            return $create();
        });
    }

    /**
     * Current usage against every `PlanLimit`, for the plan/limits panel.
     * Built from the exact same per-metric counting methods `check()`/
     * `withLimit()` use, so this can never disagree with what they would
     * decide. Includes `period` alongside `posts_per_month` — the UTC
     * (`Y-m`) calendar month this count is scoped to — so a UI can say
     * when the count resets without guessing at the timezone itself.
     *
     * @return array{
     *     channels: array{current: int, max: int|null},
     *     posts_per_month: array{current: int, max: int|null, period: string},
     *     media_storage_mb: array{current: int, max: int|null},
     * }
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
                'current' => $this->postCount($organization),
                'max' => $limits[PlanLimit::POSTS_PER_MONTH->value],
                'period' => $this->currentPeriod(),
            ],
            PlanLimit::MEDIA_STORAGE_MB->value => [
                'current' => $this->toDisplayUnit(PlanLimit::MEDIA_STORAGE_MB, $this->mediaStorageBytes($organization)),
                'max' => $limits[PlanLimit::MEDIA_STORAGE_MB->value],
            ],
        ];
    }

    private function guardDelta(int $delta): void
    {
        if ($delta < 1) {
            throw new InvalidArgumentException('PlanLimitService delta must be at least 1, got '.$delta.'.');
        }
    }

    private function maxFor(Organization $organization, PlanLimit $limit): ?int
    {
        return $this->registry->forOrganization($organization)['limits'][$limit->value];
    }

    /**
     * Current usage in the SAME unit `$max` is compared against — a plain
     * count for `CHANNELS`/`POSTS_PER_MONTH`, raw bytes for
     * `MEDIA_STORAGE_MB` (see `check()`'s docblock for why `$delta` for
     * that metric is bytes too).
     */
    private function currentUsage(Organization $organization, PlanLimit $limit): int
    {
        return match ($limit) {
            PlanLimit::CHANNELS => $this->channelCount($organization),
            PlanLimit::POSTS_PER_MONTH => $this->postCount($organization),
            PlanLimit::MEDIA_STORAGE_MB => $this->mediaStorageBytes($organization),
        };
    }

    private function assertWithinLimit(PlanLimit $limit, int $current, int $delta, int $max): void
    {
        if ($current + $delta > $max) {
            throw new PlanLimitExceededException(
                $limit,
                $this->toDisplayUnit($limit, $current),
                $this->toDisplayUnit($limit, $max),
            );
        }
    }

    /**
     * The inverse of `toDisplayUnit()`: converts a configured maximum
     * (`config/plans.php`'s `media_storage_mb`, already in megabytes) into
     * the same raw unit `currentUsage()`/`$delta` are compared in — bytes
     * for `MEDIA_STORAGE_MB`, unchanged otherwise. `check()`/`withLimit()`
     * both call this before `assertWithinLimit()` — omitting it compares a
     * byte sum directly against a bare "200" (meant as 200 MB) instead of
     * 200 * 1024 * 1024, which throws on nearly any real upload.
     */
    private function toComparisonUnit(PlanLimit $limit, int $configuredMax): int
    {
        return $limit === PlanLimit::MEDIA_STORAGE_MB
            ? $configuredMax * 1024 * 1024
            : $configuredMax;
    }

    /**
     * `$rawValue` is bytes for `MEDIA_STORAGE_MB` (both `current` and
     * `max` are converted through this before ever reaching a caller —
     * `assertWithinLimit()` receives `$max` already in bytes, via
     * `toComparisonUnit()`, so converting it back here for the exception/
     * `usage()` body is symmetric), a plain count otherwise.
     */
    private function toDisplayUnit(PlanLimit $limit, int $rawValue): int
    {
        return $limit === PlanLimit::MEDIA_STORAGE_MB
            ? (int) ceil($rawValue / (1024 * 1024))
            : $rawValue;
    }

    /**
     * Live (non-soft-deleted) integration count. `withoutGlobalScope
     * ('organization')` by name (never the blanket `withoutGlobalScopes()`
     * — this codebase's established convention, see e.g.
     * `TokenRefreshService`), because this must give the right answer
     * for `$organization` regardless of the ambient `OrganizationContext`
     * — a console command or job calling this has none. Naming the scope
     * (rather than stripping every scope) is what keeps `SoftDeletes`'
     * own global scope intact, so a trashed row is still excluded.
     */
    private function channelCount(Organization $organization): int
    {
        return Integration::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->count();
    }

    /**
     * Raw bytes — see class docblock for why every comparison happens in
     * bytes and rounding to megabytes is applied exactly once, at
     * display time (`toDisplayUnit()`), never here.
     */
    private function mediaStorageBytes(Organization $organization): int
    {
        return (int) Media::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->sum('size_bytes');
    }

    private function currentPeriod(): string
    {
        return Date::now('UTC')->format('Y-m');
    }

    /**
     * `COUNT(*)` over `post_groups` created within the current UTC
     * calendar month for this organization — see the class docblock for
     * why this is on demand rather than a cached, incremented counter.
     * `withoutGlobalScope('organization')` for the same ambient-context
     * independence reason as `channelCount()`.
     */
    private function postCount(Organization $organization): int
    {
        $start = Date::now('UTC')->startOfMonth();
        $end = $start->copy()->addMonthNoOverflow();

        return PostGroup::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }
}
