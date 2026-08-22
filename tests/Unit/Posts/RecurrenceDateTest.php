<?php

use App\Enums\RepeatUnit;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\RecurrenceService;

/*
 * Block 4: the arithmetic half of recurring publication, on its own.
 * `nextDate()` touches no database and has no side effects, which is the
 * point — the rules it encodes (wall-clock shifts in the author's
 * timezone, calendar months, the `repeat_until` stop) are the ones most
 * likely to be quietly wrong, and they deserve tests that do not need a
 * publish to run first.
 *
 * Every group here is built in memory with its `creator` relation
 * already set, so nothing lazy-loads: this file lives in tests/Unit,
 * which runs without RefreshDatabase.
 */
function repeatingGroup(string $publishDate, RepeatUnit $unit, int $interval, ?string $until, ?string $timezone = 'UTC'): PostGroup
{
    $group = new PostGroup([
        'publish_date' => $publishDate,
        'repeat_unit' => $unit,
        'repeat_interval' => $interval,
        'repeat_until' => $until,
    ]);

    $group->setRelation('creator', $timezone === null ? null : new User(['timezone' => $timezone]));

    return $group;
}

it('advances a daily series by its interval', function () {
    $next = app(RecurrenceService::class)->nextDate(
        repeatingGroup('2026-05-04 09:00:00', RepeatUnit::DAY, 3, '2026-12-01 00:00:00')
    );

    expect($next?->toIso8601String())->toBe('2026-05-07T09:00:00+00:00');
});

it('advances a weekly series by whole weeks', function () {
    $next = app(RecurrenceService::class)->nextDate(
        repeatingGroup('2026-05-04 09:00:00', RepeatUnit::WEEK, 2, '2026-12-01 00:00:00')
    );

    expect($next?->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
});

it('keeps the wall clock of the author across a daylight-saving change', function () {
    // 2026-03-08 is when America/Chicago moves from UTC-6 to UTC-5. A
    // post at 09:00 local on the 5th must stay at 09:00 local a week
    // later — which is a DIFFERENT absolute instant than "+7×24h", and
    // that difference is the whole reason the shift is computed in the
    // author's timezone rather than in UTC.
    $group = repeatingGroup('2026-03-05 15:00:00', RepeatUnit::WEEK, 1, '2026-12-01 00:00:00', 'America/Chicago');

    $next = app(RecurrenceService::class)->nextDate($group);

    expect($next?->copy()->setTimezone('America/Chicago')->format('Y-m-d H:i'))->toBe('2026-03-12 09:00')
        // 14:00Z, not 15:00Z: the same wall clock is now one hour earlier in UTC.
        ->and($next?->utc()->format('H:i'))->toBe('14:00');
});

it('lands a monthly series on the last day of a short month instead of overflowing', function () {
    $next = app(RecurrenceService::class)->nextDate(
        repeatingGroup('2026-01-31 09:00:00', RepeatUnit::MONTH, 1, '2026-12-01 00:00:00')
    );

    // addMonthsNoOverflow: February 28th, not March 3rd.
    expect($next?->format('Y-m-d'))->toBe('2026-02-28');
});

it('stops the series once the next occurrence would pass repeat_until', function () {
    $next = app(RecurrenceService::class)->nextDate(
        repeatingGroup('2026-05-04 09:00:00', RepeatUnit::DAY, 7, '2026-05-10 00:00:00')
    );

    expect($next)->toBeNull();
});

it('allows an occurrence that lands exactly on repeat_until', function () {
    $next = app(RecurrenceService::class)->nextDate(
        repeatingGroup('2026-05-04 09:00:00', RepeatUnit::DAY, 7, '2026-05-11 09:00:00')
    );

    expect($next?->toIso8601String())->toBe('2026-05-11T09:00:00+00:00');
});

it('returns nothing for a group that does not repeat', function () {
    $group = new PostGroup(['publish_date' => '2026-05-04 09:00:00']);
    $group->setRelation('creator', new User(['timezone' => 'UTC']));

    expect(app(RecurrenceService::class)->nextDate($group))->toBeNull();
});

it('falls back to UTC when the author has been deleted', function () {
    // `created_by` is nullOnDelete(), so an offboarded author leaves a
    // live series behind with no timezone to read. That must not throw.
    $next = app(RecurrenceService::class)->nextDate(
        repeatingGroup('2026-03-05 15:00:00', RepeatUnit::WEEK, 1, '2026-12-01 00:00:00', null)
    );

    expect($next?->toIso8601String())->toBe('2026-03-12T15:00:00+00:00');
});
