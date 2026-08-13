/**
 * Converts between `minutes_from_week_start` — the raw integer
 * `IntegrationTimeSlot` stores (0..10079, anchored to **Monday 00:00
 * UTC**; see the `integration_time_slots` migration's comment) — and a
 * weekday+time pair a human can pick in a select/time input.
 *
 * The anchor is UTC, not the viewer's local time or the organization's
 * timezone: two admins in different timezones editing the same channel
 * must see the same underlying schedule converted for *their own*
 * clock, and Task 21's slot finder (which decides "is it time to post
 * this integration's next slot") must agree on what a stored integer
 * means without also having to know which timezone it was entered in.
 * `timeZone` here is purely a display/input convenience — callers pass
 * `auth.user.timezone` (the signed-in editor's profile timezone) so the
 * TimeSlotEditor shows "Mon 09:00" instead of a raw UTC offset, but the
 * value persisted via `PUT /api/integrations/{id}` is always the UTC
 * minute count `localToUtcMinutes()` produces.
 *
 * No date library: both directions go through a fixed reference week
 * (the most recent Monday 00:00 UTC on or before "now") and the
 * platform `Intl` API, using the well-known offset-diffing trick
 * (`toLocaleString` round-trip) for the local->UTC direction. Because
 * the reference is "now", the DST offset used is the one in effect for
 * the *current* real-world week — a recurring slot's local-clock
 * meaning can therefore shift by up to an hour across a DST transition
 * relative to when it was configured, exactly as it would for any
 * other recurring-weekly-event tool (a calendar's "every Monday 9am"
 * has the same property). The stored integer itself never changes
 * across that shift; only its local-time display does.
 */

export const WEEKDAY_COUNT = 7;

export interface LocalSlot {
    /** 0 = Monday .. 6 = Sunday, matching the UTC anchor's week start. */
    weekday: number;
    hour: number;
    minute: number;
}

const MINUTES_PER_DAY = 24 * 60;
const MINUTES_PER_WEEK = WEEKDAY_COUNT * MINUTES_PER_DAY;

const WEEKDAY_INDEX: Record<string, number> = {
    Mon: 0,
    Tue: 1,
    Wed: 2,
    Thu: 3,
    Fri: 4,
    Sat: 5,
    Sun: 6,
};

/** The most recent Monday 00:00 UTC on or before now, as a UTC instant. */
function referenceMonday(): Date {
    const now = new Date();
    // getUTCDay(): 0=Sunday..6=Saturday. Days since the most recent Monday.
    const daysSinceMonday = (now.getUTCDay() + 6) % 7;
    const midnightUtc = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());

    return new Date(midnightUtc - daysSinceMonday * MINUTES_PER_DAY * 60_000);
}

export function utcMinutesToLocal(minutesFromWeekStart: number, timeZone: string): LocalSlot {
    const instant = new Date(referenceMonday().getTime() + minutesFromWeekStart * 60_000);

    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        weekday: 'short',
        hourCycle: 'h23',
        hour: '2-digit',
        minute: '2-digit',
    }).formatToParts(instant);

    const weekdayPart = parts.find((p) => p.type === 'weekday')?.value ?? 'Mon';
    const hour = Number(parts.find((p) => p.type === 'hour')?.value ?? '0') % 24;
    const minute = Number(parts.find((p) => p.type === 'minute')?.value ?? '0');

    return { weekday: WEEKDAY_INDEX[weekdayPart] ?? 0, hour, minute };
}

/**
 * `timeZone`'s UTC offset (in ms, positive east of UTC — e.g. +5h for
 * Asia/Karachi) at approximately the given instant: the wall-clock
 * fields `timeZone` renders `date` as, re-interpreted as a UTC instant,
 * minus `date` itself.
 *
 * Exported for `utils/zonedDateTime.ts` (Task 25's schedule picker,
 * which needs the exact same offset-diffing trick against a real
 * instant rather than this file's fixed reference week) — kept as the
 * single implementation rather than duplicated.
 */
export function zoneOffsetMs(date: Date, timeZone: string): number {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })
        .formatToParts(date)
        .reduce<Record<string, string>>((acc, part) => {
            acc[part.type] = part.value;

            return acc;
        }, {});

    const asIfUtc = Date.UTC(
        Number(parts.year),
        Number(parts.month) - 1,
        Number(parts.day),
        Number(parts.hour) % 24,
        Number(parts.minute),
        Number(parts.second),
    );

    return asIfUtc - date.getTime();
}

export function localToUtcMinutes(weekday: number, hour: number, minute: number, timeZone: string): number {
    // Interpret the desired local wall-clock fields as if they were UTC
    // — a first guess at the instant — then read `timeZone`'s offset at
    // that guess and subtract it to land on the real UTC instant whose
    // rendering in `timeZone` actually produces those fields. One
    // iteration (using the guess instant's offset rather than the true
    // instant's) is the standard approximation for this kind of
    // library-free conversion; it can be off by the DST delta only in
    // the ~1-hour window spanning a transition, which is an accepted
    // limitation — see this file's docblock.
    const reference = referenceMonday();
    const naiveUtc = new Date(reference.getTime() + (weekday * MINUTES_PER_DAY + hour * 60 + minute) * 60_000);
    const offset = zoneOffsetMs(naiveUtc, timeZone);
    const actualUtc = naiveUtc.getTime() - offset;

    const minutesFromReference = Math.round((actualUtc - reference.getTime()) / 60_000);

    // Normalise into 0..10079 in case the correction pushed the instant
    // just before this week's Monday or past the following one.
    return ((minutesFromReference % MINUTES_PER_WEEK) + MINUTES_PER_WEEK) % MINUTES_PER_WEEK;
}
