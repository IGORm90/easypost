import { zoneOffsetMs } from './timeSlots';

/**
 * Converts between a UTC ISO instant (what `publish_date` always is on
 * the wire) and the "YYYY-MM-DDTHH:mm" string a native `<input
 * type="datetime-local">` reads/writes, in a given IANA `timeZone`.
 *
 * `datetime-local` has no timezone concept of its own — it is always
 * the *browser's* local wall clock. Converting through it naively (as
 * `ScheduleControls.vue` originally did, via `Date#getHours()` /
 * `Date#toISOString()`) silently uses the browser's OS timezone instead
 * of the signed-in user's profile timezone. `CalendarGrid.vue` renders
 * every entry with FullCalendar's `timeZone: auth.user.timezone` option
 * (see `Calendar/Index.vue`'s `timezone` computed) — a post scheduled
 * here must land on the exact slot that grid shows it at, so this file
 * always converts against that same `timeZone`, passed in explicitly by
 * every caller, never `Intl.DateTimeFormat().resolvedOptions().timeZone`
 * (the browser's own zone).
 *
 * No date library: the same Intl-based offset-diffing trick
 * `utils/timeSlots.ts` uses for its local<->UTC conversion, against a
 * real instant instead of a fixed reference week.
 */

export function utcIsoToZonedInput(iso: string, timeZone: string): string {
    const date = new Date(iso);

    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    })
        .formatToParts(date)
        .reduce<Record<string, string>>((acc, part) => {
            acc[part.type] = part.value;

            return acc;
        }, {});

    // Chromium renders midnight as "24:00" under hourCycle: 'h23' in
    // some locales/zones; normalise it back to "00:00" of the same day
    // formatToParts already resolved.
    const hour = parts.hour === '24' ? '00' : parts.hour;

    return `${parts.year}-${parts.month}-${parts.day}T${hour}:${parts.minute}`;
}

/** `input` is the "YYYY-MM-DDTHH:mm" a `datetime-local` produces; `''` (no value chosen) round-trips to `''`. */
export function zonedInputToUtcIso(input: string, timeZone: string): string {
    if (!input) {
        return '';
    }

    const [datePart, timePart] = input.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hour, minute] = timePart.split(':').map(Number);

    // Same one-iteration offset-diffing approximation as
    // `timeSlots.ts#localToUtcMinutes` — see that function's docblock
    // for why a single correction is the accepted, documented limit.
    const naiveUtc = Date.UTC(year, month - 1, day, hour, minute);
    const offset = zoneOffsetMs(new Date(naiveUtc), timeZone);

    return new Date(naiveUtc - offset).toISOString();
}
