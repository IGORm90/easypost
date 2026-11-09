<?php

namespace App\Services\Posts;

use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Post;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Picks the next free publish instant for one channel's weekly recurring
 * schedule (Task 21's `GET /api/posts/find-slot?integration_id=`).
 *
 * TIMEZONE: `IntegrationTimeSlot::minutes_from_week_start` is anchored to
 * **Monday 00:00 UTC**, not any user's or organization's local zone — see
 * resources/js/utils/timeSlots.ts' docblock, which Task 17's editor
 * already established this contract against. That anchor being fixed in
 * UTC (rather than "Monday in whatever zone the integration/organization
 * happens to be in") is exactly what this class needs to agree with: a
 * candidate instant is computed as `<Monday 00:00 UTC of some week> +
 * minutes_from_week_start minutes`, no timezone conversion involved at
 * any point. `users.timezone` is irrelevant here — it only matters for
 * *displaying* a slot to a human (the editor's job), never for computing
 * which UTC instant a stored integer means.
 *
 * DST: because the anchor is a fixed UTC weekly boundary rather than a
 * local-clock wall time, this class has NO skipped/repeated-hour problem
 * at all — "Monday + 600 minutes" always means the exact same UTC instant
 * every week, unconditionally. This is a deliberate consequence of the
 * Task 17 storage decision, not something this class had to solve itself;
 * if that decision is ever revisited (e.g. slots re-anchored to a
 * timezone-aware wall clock), this class's arithmetic below would need to
 * change to the local-to-UTC conversion described in localToUtcMinutes(),
 * DST ambiguity and all.
 */
class SlotFinderService
{
    /**
     * Upper bound on how many weeks ahead this searches before giving up.
     * A channel with every slot permanently double-booked for two years
     * straight is not a case this product needs to support gracefully —
     * this exists only to turn a hypothetical infinite loop into a loud,
     * diagnosable failure.
     */
    private const int MAX_WEEKS_TO_SEARCH = 104;

    public function findNextFreeSlot(string $integrationId): Carbon
    {
        return $this->findNextFreeSlots($integrationId, 1)[0];
    }

    /**
     * The next `$count` free instants for one channel, earliest first —
     * the «Кобальт» composer offers several as buttons rather than only
     * the single next one. Same search and the same "occupied" rule as
     * before; it just keeps collecting instead of returning the first.
     *
     * A channel with no configured slots has no schedule to enumerate, so
     * it answers the single fallback instant (now + 1h, rounded) however
     * many were asked for — inventing several "free slots" there would
     * present arbitrary times as if the channel's schedule had produced
     * them.
     *
     * @return list<Carbon>
     */
    public function findNextFreeSlots(string $integrationId, int $count): array
    {
        // Org-scoped via BelongsToOrganization's global scope, same
        // pattern as PostGroupService::resolveIntegrations(): a
        // cross-tenant id resolves to null here even though it exists. FindSlotRequest
        // already refuses it (a 422 identical to a missing id), so this 403 is a
        // backstop for a caller that does not go through that request.
        $integration = Integration::query()->find($integrationId);

        if ($integration === null) {
            throw new AuthorizationException;
        }

        $slots = $integration->timeSlots()
            ->orderBy('minutes_from_week_start')
            ->pluck('minutes_from_week_start');

        if ($slots->isEmpty()) {
            return [$this->roundUpToFiveMinutes(Carbon::now()->addHour())];
        }

        $now = Carbon::now();
        $weekStart = $this->weekStartUtc($now);
        $found = [];

        for ($week = 0; $week < self::MAX_WEEKS_TO_SEARCH; $week++) {
            $candidateWeekStart = $weekStart->copy()->addWeeks($week);

            foreach ($slots as $minutes) {
                $candidate = $candidateWeekStart->copy()->addMinutes((int) $minutes);

                if ($candidate->lessThanOrEqualTo($now)) {
                    continue;
                }

                if (! $this->isOccupied($integration, $candidate)) {
                    $found[] = $candidate;

                    if (count($found) >= $count) {
                        return $found;
                    }
                }
            }
        }

        // Fewer than asked for within the search window: what was found
        // is still useful. Only finding nothing at all is the loud,
        // diagnosable failure MAX_WEEKS_TO_SEARCH exists for.
        if ($found !== []) {
            return $found;
        }

        throw new RuntimeException(
            "No free slot found for integration {$integration->id} within ".self::MAX_WEEKS_TO_SEARCH.' weeks.'
        );
    }

    /**
     * The most recent Monday 00:00 UTC on or before `$now` — the same
     * reference-week anchor resources/js/utils/timeSlots.ts'
     * `referenceMonday()` uses, expressed in PHP/Carbon instead of JS.
     */
    private function weekStartUtc(Carbon $now): Carbon
    {
        $utc = $now->copy()->utc()->startOfDay();

        // Carbon::dayOfWeekIso: Monday = 1 .. Sunday = 7.
        return $utc->subDays($utc->dayOfWeekIso - 1);
    }

    /**
     * "Occupied" is scoped to THIS channel only: another PostGroup with a
     * segment on the SAME integration at the exact candidate instant
     * blocks it, but a post scheduled to a different channel at the same
     * moment does not — posting to two different networks at once is the
     * normal case (a cross-posted campaign), not a conflict. Only
     * SCHEDULED/QUEUE/PUBLISHED groups count: a DRAFT's `publish_date` is
     * optional, unvalidated placeholder data (see PostGroupService's
     * docblock), not a real commitment to that instant, so it must not
     * block a real scheduling decision.
     *
     * `SCHEDULED` (durable-scheduling rewrite): a post durably waiting
     * for its due date sits here for the whole time between scheduling
     * and publish — `QUEUE` now means "claimed, a job exists right now",
     * a window normally lasting seconds. Dropping `SCHEDULED` from this
     * list would mean almost every real upcoming post no longer counts
     * as occupying its slot at all, defeating this method's entire
     * purpose.
     */
    private function isOccupied(Integration $integration, Carbon $candidate): bool
    {
        return Post::query()
            ->where('integration_id', $integration->id)
            ->whereHas('group', function ($query) use ($candidate): void {
                $query->where('publish_date', $candidate)
                    ->whereIn('state', [PostState::SCHEDULED, PostState::QUEUE, PostState::PUBLISHED]);
            })
            ->exists();
    }

    private function roundUpToFiveMinutes(Carbon $date): Carbon
    {
        $date = $date->copy()->second(0)->microsecond(0);
        $remainder = $date->minute % 5;

        if ($remainder !== 0) {
            $date->addMinutes(5 - $remainder);
        }

        return $date;
    }
}
