<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * The unit a recurring post's interval is counted in — the `unit` half
 * of `post_groups.repeat_unit` + `repeat_interval`.
 *
 * A single `interval_days` column was the first design and it cannot
 * express "monthly": a month is not 30 days, and the 31st of a month
 * plus 30 days lands on a different day-of-month every time. Making the
 * unit explicit is what lets `advance()` below use calendar arithmetic
 * where the user asked for calendar arithmetic.
 *
 * Both facts that vary per unit live here rather than being spread
 * across the validator and the service: the ceiling on a sane interval
 * (`maxInterval()`) and how one step is actually taken (`advance()`).
 */
enum RepeatUnit: string
{
    case DAY = 'DAY';
    case WEEK = 'WEEK';
    case MONTH = 'MONTH';

    /**
     * The largest interval that still means something in this unit.
     * These are ceilings on absurdity, not on usefulness — 365 days, 52
     * weeks and 12 months are all "about a year", which is also the
     * furthest `repeat_until` may be set (see ValidatesPostChains).
     */
    public function maxInterval(): int
    {
        return match ($this) {
            self::DAY => 365,
            self::WEEK => 52,
            self::MONTH => 12,
        };
    }

    /**
     * One step forward from `$date`, in this unit.
     *
     * `addMonthsNoOverflow()`, never `addMonths()`: the 31st of January
     * plus one month has to be the 28th/29th of February, not the 2nd or
     * 3rd of March. Carbon's plain `addMonths()` overflows into the next
     * month, which for a monthly series compounds — every occurrence
     * drifting a few days further from the date the user picked.
     *
     * The caller is responsible for handing in a date already converted
     * to the author's timezone: this is wall-clock arithmetic, and
     * "+1 week" across a DST boundary must keep 09:00 at 09:00 rather
     * than moving it to 08:00 or 10:00. See RecurrenceService::nextDate().
     */
    public function advance(CarbonInterface $date, int $interval): CarbonInterface
    {
        return match ($this) {
            self::DAY => $date->copy()->addDays($interval),
            self::WEEK => $date->copy()->addWeeks($interval),
            self::MONTH => $date->copy()->addMonthsNoOverflow($interval),
        };
    }
}
