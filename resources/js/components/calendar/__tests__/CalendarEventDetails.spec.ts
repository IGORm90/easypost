import { mount } from '@vue/test-utils'
import CalendarEventDetails from '../CalendarEventDetails.vue'
import en from '../../../i18n/en.json'

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: 'group-1',
    state: 'QUEUE',
    publish_date: '2026-08-20T09:00:00.000000Z',
    share_token: null,
    repeats: false,
    tags: [{ id: 't1', name: 'Promo', color: '#336699' }],
    posts: [
      { id: 'p1', content: 'a very long first post\nwith a second line', state: 'QUEUE', integration: { id: 'i1', provider_identifier: 'telegram', name: 'Alpha', picture: null } },
      { id: 'p2', content: 'second', state: 'QUEUE', integration: { id: 'i2', provider_identifier: 'bluesky', name: 'Beta', picture: null } },
    ],
    ...overrides,
  }
}

function mountDetails(props: { entry: ReturnType<typeof entry> | null; locked?: boolean }) {
  return mount(CalendarEventDetails, { props: { timezone: 'UTC', locked: false, ...props }, attachTo: document.body })
}

afterEach(() => {
  document.body.innerHTML = ''
})

it('stays closed without an entry', () => {
  mountDetails({ entry: null })
  expect(document.querySelector('[data-test="event-details"]')).toBeNull()
})

it('shows the full post: every channel, full text, tags', () => {
  mountDetails({ entry: entry() })
  const text = document.querySelector('[data-test="event-details"]')?.textContent ?? ''

  expect(text).toContain('Alpha')
  expect(text).toContain('Beta')
  expect(text).toContain('with a second line')
  expect(text).toContain('Promo')
})

it('relays every action with the group id, and hides Remove on a locked post', async () => {
  const w = mountDetails({ entry: entry({ id: 'g-9' }) })
  const button = (label: string) => Array.from(document.querySelectorAll('button')).find((b) => b.textContent?.trim() === label)

  button(en.calendar.details.edit)?.click()
  button(en.calendar.share.menuItem)?.click()
  button(en.calendar.remove)?.click()

  expect(w.emitted('edit')).toEqual([['g-9']])
  expect(w.emitted('share')).toEqual([['g-9']])
  expect(w.emitted('remove')).toEqual([['g-9']])

  await w.setProps({ locked: true })
  expect(button(en.calendar.remove)).toBeUndefined()
})
