import { zonedInputToUtcIso } from './zonedDateTime';

/**
 * Week arithmetic for the channel-lanes calendar view, done entirely on
 * 'YYYY-MM-DD' day keys — the same keys `zonedDayKey()` produces.
 *
 * Why keys and not Date objects: a Date's getDay()/setDate() answer in
 * the *browser's* zone, so a Tbilisi profile viewed from a Chicago laptop
 * would page through Chicago weeks. A day key has no zone at all; the
 * profile's zone enters exactly once, in `weekRange()`, when the week is
 * turned into the UTC instants the API filters on.
 */
function keyToUtcDate(key: string): Date {
    const [year, month, day] = key.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

function utcDateToKey(date: Date): string {
    return date.toISOString().slice(0, 10);
}

export function addDays(key: string, days: number): string {
    const date = keyToUtcDate(key);
    date.setUTCDate(date.getUTCDate() + days);

    return utcDateToKey(date);
}

/** The Monday on or before `key` (ISO weeks start on Monday, as the app's week view does). */
export function weekStartKey(key: string): string {
    const weekday = keyToUtcDate(key).getUTCDay(); // 0 = Sunday

    return addDays(key, weekday === 0 ? -6 : 1 - weekday);
}

export function weekDays(startKey: string): string[] {
    return Array.from({ length: 7 }, (_, index) => addDays(startKey, index));
}

/** Local midnight of the Monday to local midnight of the next Monday, in `timeZone`, as UTC ISO strings. */
export function weekRange(startKey: string, timeZone: string): { from: string; to: string } {
    return {
        from: zonedInputToUtcIso(`${startKey}T00:00`, timeZone),
        to: zonedInputToUtcIso(`${addDays(startKey, 7)}T00:00`, timeZone),
    };
}
