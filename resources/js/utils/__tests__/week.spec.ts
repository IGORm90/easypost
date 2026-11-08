import { addDays, weekDays, weekRange, weekStartKey } from '../week';

/*
 * The channel-lanes view pages through whole weeks in the *profile's*
 * timezone. Everything here works on 'YYYY-MM-DD' day keys (the same keys
 * zonedDayKey() produces) so a week boundary never depends on the zone the
 * browser happens to run in — TZ is pinned to America/Chicago in the test
 * setup precisely so a browser-zone leak would show up here.
 */
it('finds the Monday that starts the week of any day', () => {
    expect(weekStartKey('2026-11-09')).toBe('2026-11-09'); // a Monday
    expect(weekStartKey('2026-11-15')).toBe('2026-11-09'); // a Sunday
    expect(weekStartKey('2026-11-11')).toBe('2026-11-09');
    expect(weekStartKey('2026-03-01')).toBe('2026-02-23'); // across a month
});

it('adds days across month and year ends', () => {
    expect(addDays('2026-12-30', 3)).toBe('2027-01-02');
    expect(addDays('2026-03-02', -2)).toBe('2026-02-28');
});

it('lists the seven days of the week', () => {
    expect(weekDays('2026-11-09')).toEqual([
        '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15',
    ]);
});

it('turns a week into the UTC range the calendar API takes, from local midnight to local midnight', () => {
    expect(weekRange('2026-11-09', 'Asia/Tbilisi')).toEqual({
        from: '2026-11-08T20:00:00.000Z',
        to: '2026-11-15T20:00:00.000Z',
    });
});
