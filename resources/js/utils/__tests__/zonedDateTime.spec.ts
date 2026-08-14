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

// Task 25 review, Finding 3 (round 2): a single offset guess is wrong
// for every unambiguous local time within roughly one transition-delta
// of a DST boundary, not just the literal ambiguous/skipped hour — see
// zonedInputToUtcIso()'s docblock. These values were independently
// computed and cross-checked (Intl.DateTimeFormat by hand) before being
// pinned here, on real 2026 transition dates.
describe('across a DST transition', () => {
  it('resolves unambiguous local times correctly on a northern-hemisphere spring-forward day (America/New_York, 2026-03-08, 02:00->03:00 skipped)', () => {
    // 03:00 and 06:00 local are both after the skipped hour — unambiguously EDT (UTC-4).
    expect(zonedInputToUtcIso('2026-03-08T03:00', 'America/New_York')).toBe('2026-03-08T07:00:00.000Z')
    expect(zonedInputToUtcIso('2026-03-08T06:00', 'America/New_York')).toBe('2026-03-08T10:00:00.000Z')
    expect(utcIsoToZonedInput('2026-03-08T07:00:00.000Z', 'America/New_York')).toBe('2026-03-08T03:00')
  })

  it('resolves unambiguous local times correctly on a northern-hemisphere fall-back day (America/New_York, 2026-11-01, 02:00 occurs twice)', () => {
    // 02:00 and 05:00 local, read AFTER the fold, are unambiguously EST (UTC-5).
    expect(zonedInputToUtcIso('2026-11-01T02:00', 'America/New_York')).toBe('2026-11-01T07:00:00.000Z')
    expect(zonedInputToUtcIso('2026-11-01T05:00', 'America/New_York')).toBe('2026-11-01T10:00:00.000Z')
  })

  it('resolves unambiguous local times correctly on a southern-hemisphere spring-forward day (Australia/Sydney, 2026-10-04, 02:00->03:00 skipped)', () => {
    // 01:00 local is before the transition (AEST/UTC+10); 04:00 is after it (AEDT/UTC+11).
    expect(zonedInputToUtcIso('2026-10-04T01:00', 'Australia/Sydney')).toBe('2026-10-03T15:00:00.000Z')
    expect(zonedInputToUtcIso('2026-10-04T04:00', 'Australia/Sydney')).toBe('2026-10-03T17:00:00.000Z')
  })

  it('resolves an unambiguous local time correctly on a southern-hemisphere fall-back day (Australia/Sydney, 2026-04-05, 03:00 is unambiguously AEST)', () => {
    expect(zonedInputToUtcIso('2026-04-05T03:00', 'Australia/Sydney')).toBe('2026-04-04T17:00:00.000Z')
    expect(zonedInputToUtcIso('2026-04-05T04:00', 'Australia/Sydney')).toBe('2026-04-04T18:00:00.000Z')
  })
})
