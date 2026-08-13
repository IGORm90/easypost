import { utcIsoToZonedInput, zonedInputToUtcIso } from '../zonedDateTime';

it('renders a UTC instant as the profile timezone wall clock, not the test runner locale', () => {
    // 2026-08-20T09:00:00Z is 12:00 in Europe/Moscow (UTC+3, no DST).
    expect(utcIsoToZonedInput('2026-08-20T09:00:00.000Z', 'Europe/Moscow')).toBe('2026-08-20T12:00');
});

it('parses a datetime-local value as the profile timezone wall clock, producing the correct UTC instant', () => {
    // 12:00 Europe/Moscow (UTC+3) is 09:00Z.
    expect(zonedInputToUtcIso('2026-08-20T12:00', 'Europe/Moscow')).toBe('2026-08-20T09:00:00.000Z');
});

it('round-trips through both directions for a timezone west of UTC', () => {
    const iso = '2026-08-20T15:30:00.000Z';
    const local = utcIsoToZonedInput(iso, 'America/New_York'); // UTC-4 in August (EDT)
    expect(local).toBe('2026-08-20T11:30');
    expect(zonedInputToUtcIso(local, 'America/New_York')).toBe(iso);
});

it('an empty input round-trips to an empty string rather than throwing', () => {
    expect(zonedInputToUtcIso('', 'Europe/Moscow')).toBe('');
});

it('two different profile timezones render the same UTC instant differently — never the browser/runner zone', () => {
    const iso = '2026-08-20T09:00:00.000Z';
    expect(utcIsoToZonedInput(iso, 'UTC')).toBe('2026-08-20T09:00');
    expect(utcIsoToZonedInput(iso, 'Europe/Moscow')).toBe('2026-08-20T12:00');
});
