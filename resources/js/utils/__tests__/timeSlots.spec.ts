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
