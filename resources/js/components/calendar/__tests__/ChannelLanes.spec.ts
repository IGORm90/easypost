import { mount } from '@vue/test-utils'
import ChannelLanes from '../ChannelLanes.vue'
import { i18n } from '../../../i18n'
import type { CalendarEntry, CalendarIntegration } from '../../../stores/posts'

/*
 * «Кобальт»'s main screen: one lane per channel, one column per day, so
 * "what goes out where this week" reads as a grid instead of a pile of
 * cards. The view only rearranges what /calendar already returns — one
 * entry per PostGroup with a segment per channel — so the interesting
 * behaviour is all in how segments are placed.
 */
const DAYS = ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15']

function channel(id: string, name: string): CalendarIntegration {
  return { id, name, provider_identifier: 'fake', picture: null }
}

function entry(id: string, publishDate: string, channels: CalendarIntegration[], state = 'SCHEDULED'): CalendarEntry {
  return {
    id,
    state,
    publish_date: publishDate,
    share_token: null,
    repeats: false,
    tags: [],
    posts: channels.map((integration, index) => ({ id: `${id}-p${index}`, content: `Post ${id}`, state, integration })),
  }
}

const A = channel('int-a', 'Studio channel')
const B = channel('int-b', 'Cafe page')

function mountLanes(props: Partial<InstanceType<typeof ChannelLanes>['$props']> = {}) {
  i18n.global.locale.value = 'en'

  return mount(ChannelLanes, {
    props: { entries: [], channels: [A, B], days: DAYS, todayKey: '2026-11-09', timezone: 'Asia/Tbilisi', ...props },
    global: { plugins: [i18n] },
  })
}

function cell(w: ReturnType<typeof mountLanes>, channelId: string, day: string) {
  return w.get(`[data-test="lane"][data-channel="${channelId}"] [data-day="${day}"]`)
}

it('draws one lane per channel, in the order the channels come', () => {
  const lanes = mountLanes().findAll('[data-test="lane"]')

  expect(lanes.map((lane) => lane.attributes('data-channel'))).toEqual(['int-a', 'int-b'])
  expect(lanes[0].text()).toContain('Studio channel')
})

it('places a post on its day in the profile timezone, not the browser\'s, and shows the local time', () => {
  // 21:30 UTC on Monday is 01:30 on Tuesday in Tbilisi (UTC+4).
  const w = mountLanes({ entries: [entry('g1', '2026-11-09T21:30:00.000000Z', [A])] })

  expect(cell(w, 'int-a', '2026-11-09').find('[data-test="lane-post"]').exists()).toBe(false)
  expect(cell(w, 'int-a', '2026-11-10').get('[data-test="lane-post"]').text()).toContain('01:30')
})

it('shows a post that targets two channels in both lanes', () => {
  const w = mountLanes({ entries: [entry('g1', '2026-11-11T08:00:00.000000Z', [A, B])] })

  expect(cell(w, 'int-a', '2026-11-11').findAll('[data-test="lane-post"]')).toHaveLength(1)
  expect(cell(w, 'int-b', '2026-11-11').findAll('[data-test="lane-post"]')).toHaveLength(1)
})

it('sorts a day\'s posts by time', () => {
  const w = mountLanes({
    entries: [entry('late', '2026-11-11T15:00:00.000000Z', [A]), entry('early', '2026-11-11T05:00:00.000000Z', [A])],
  })

  expect(cell(w, 'int-a', '2026-11-11').findAll('[data-test="lane-post"]').map((post) => post.text())).toEqual([
    expect.stringContaining('Post early'),
    expect.stringContaining('Post late'),
  ])
})

it('opens the editor for the whole group when a post is clicked', async () => {
  const w = mountLanes({ entries: [entry('g1', '2026-11-11T08:00:00.000000Z', [A, B])] })

  await cell(w, 'int-b', '2026-11-11').get('[data-test="lane-post"]').trigger('click')

  expect(w.emitted('edit')).toEqual([['g1']])
})

it('tints a failed post and spells its state out, never colour alone', () => {
  const w = mountLanes({ entries: [entry('g1', '2026-11-11T08:00:00.000000Z', [A], 'ERROR')] })
  const post = cell(w, 'int-a', '2026-11-11').get('[data-test="lane-post"]')

  expect(post.classes()).toContain('bg-danger-soft')
  expect(post.text()).toContain(i18n.global.t('calendar.state.ERROR'))
})

it('still gives a lane to a channel that has posts but is not in the channel list', () => {
  const gone = channel('int-gone', 'Disabled channel')
  const w = mountLanes({ entries: [entry('g1', '2026-11-11T08:00:00.000000Z', [gone])] })

  expect(w.findAll('[data-test="lane"]').map((lane) => lane.attributes('data-channel'))).toEqual(['int-a', 'int-b', 'int-gone'])
})

it('marks today\'s column for assistive technology as well as visually', () => {
  const headers = mountLanes().findAll('[data-test="lane-day"]')

  expect(headers[0].attributes('aria-current')).toBe('date')
  expect(headers[1].attributes('aria-current')).toBeUndefined()
})

it('says so when there is no channel to draw a lane for', () => {
  const w = mountLanes({ channels: [] })

  expect(w.find('[data-test="lane"]').exists()).toBe(false)
  expect(w.text()).toContain(i18n.global.t('calendar.lanes.empty'))
})

it('says under each channel whether it can publish, and flags one that needs reconnecting', () => {
  const broken = { ...channel('int-c', 'Old page'), refresh_needed: true, disabled: false }
  const w = mountLanes({ channels: [{ ...A, refresh_needed: false, disabled: false }, broken] })

  const [ok, bad] = w.findAll('[data-test="lane-status"]')
  expect(ok.text()).toBe(i18n.global.t('channels.status.active'))
  expect(bad.text()).toBe(i18n.global.t('channels.status.refreshNeeded'))
  expect(bad.classes()).toContain('text-danger')
})
