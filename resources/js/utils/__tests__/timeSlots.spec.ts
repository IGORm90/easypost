import { localToUtcMinutes, utcMinutesToLocal, WEEKDAY_COUNT } from '../timeSlots'

// `minutes_from_week_start` is anchored to Monday 00:00 UTC (see the
// `integration_time_slots` migration's comment) — these tests pin that
// anchor directly, in UTC, so they never depend on the machine's local
// timezone or the current real-world DST state.

it('utcMinutesToLocal() with timeZone UTC returns Monday 00:00 for minutes=0', () => {
  expect(utcMinutesToLocal(0, 'UTC')).toEqual({ weekday: 0, hour: 0, minute: 0 })
})

it('utcMinutesToLocal() with timeZone UTC returns Sunday 23:59 for the last minute of the week', () => {
  expect(utcMinutesToLocal(10079, 'UTC')).toEqual({ weekday: 6, hour: 23, minute: 59 })
})

it('utcMinutesToLocal() converts into a positive-offset timezone, rolling the weekday forward', () => {
  // Monday 23:00 UTC + 5 hours = Tuesday 04:00 in a UTC+5 zone (Asia/Karachi has no DST).
  const minutes = 23 * 60
  expect(utcMinutesToLocal(minutes, 'Asia/Karachi')).toEqual({ weekday: 1, hour: 4, minute: 0 })
})

it('utcMinutesToLocal() converts into a negative-offset timezone, rolling the weekday backward', () => {
  // Monday 02:00 UTC - 5 hours = Sunday 21:00 the previous week in a fixed UTC-5 zone.
  const minutes = 2 * 60
  expect(utcMinutesToLocal(minutes, 'America/Bogota')).toEqual({ weekday: 6, hour: 21, minute: 0 })
})

it('localToUtcMinutes() is the inverse of utcMinutesToLocal() for a fixed-offset zone', () => {
  const minutes = 3 * 24 * 60 + 14 * 60 + 30
  const local = utcMinutesToLocal(minutes, 'America/Bogota')
  expect(localToUtcMinutes(local.weekday, local.hour, local.minute, 'America/Bogota')).toBe(minutes)
})

it('localToUtcMinutes() with timeZone UTC is the identity', () => {
  expect(localToUtcMinutes(2, 9, 15, 'UTC')).toBe(2 * 24 * 60 + 9 * 60 + 15)
})

it('WEEKDAY_COUNT is 7', () => {
  expect(WEEKDAY_COUNT).toBe(7)
})

// Task 25 review, Finding 3 (round 2): a single offset guess is wrong
// for every unambiguous local time within roughly one transition-delta
// of a DST boundary, not just the literal ambiguous/skipped hour — see
// localToUtcMinutes()'s docblock. These pin `now` (via fake timers) into
// the real-world week of a known transition so `referenceMonday()`
// lands on that exact week, then assert specific, independently
// verified UTC instants for unambiguous local times on the transition
// day itself.
describe('localToUtcMinutes() across a DST transition', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('resolves unambiguous local times correctly on a northern-hemisphere spring-forward day (America/New_York, 2026-03-08)', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(Date.UTC(2026, 2, 5, 12, 0, 0))) // Thursday within that week

    // Sunday (weekday 6) 03:00 and 06:00 local — both after the 02:00
    // skipped hour, both unambiguously EDT (UTC-4).
    expect(localToUtcMinutes(6, 3, 0, 'America/New_York')).toBe(9060)
    expect(localToUtcMinutes(6, 6, 0, 'America/New_York')).toBe(9240)

    // Cross-check against the independently computed UTC instant.
    const reference = Date.UTC(2026, 2, 2, 0, 0, 0) // that week's Monday 00:00 UTC
    expect(new Date(reference + 9060 * 60_000).toISOString()).toBe('2026-03-08T07:00:00.000Z')
  })

  it('resolves unambiguous local times correctly on a southern-hemisphere spring-forward day (Australia/Sydney, 2026-10-04)', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(Date.UTC(2026, 8, 30, 12, 0, 0))) // Wednesday within that week

    // Sunday (weekday 6) 01:00 (before the transition, AEST/UTC+10) and
    // 04:00 (after it, AEDT/UTC+11).
    expect(localToUtcMinutes(6, 1, 0, 'Australia/Sydney')).toBe(8100)
    expect(localToUtcMinutes(6, 4, 0, 'Australia/Sydney')).toBe(8220)

    const reference = Date.UTC(2026, 8, 28, 0, 0, 0) // that week's Monday 00:00 UTC
    expect(new Date(reference + 8220 * 60_000).toISOString()).toBe('2026-10-03T17:00:00.000Z')
  })
})
