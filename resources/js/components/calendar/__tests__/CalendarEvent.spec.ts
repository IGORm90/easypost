import { mount } from '@vue/test-utils'
import CalendarEvent from '../CalendarEvent.vue'
import en from '../../../i18n/en.json'

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: 'group-1',
    state: 'QUEUE',
    publish_date: '2026-08-20T09:00:00.000000Z',
    tags: [],
    posts: [{ id: 'post-1', content: 'hello there', state: 'QUEUE', integration: { id: 'int-1', provider_identifier: 'telegram', name: 'Announcements', picture: null } }],
    ...overrides,
  }
}

it('a DRAFT card is distinguishable by more than colour: dashed border plus a text badge, never a warning colour', () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ state: 'DRAFT' }), locked: false } })

  // Structural signal (color.md §3.3/§4: never colour alone).
  expect(w.classes()).toContain('border-dashed')

  // Textual signal: the state is spelled out, not merely implied by colour.
  expect(w.text()).toContain(en.calendar.state.DRAFT)

  // color.md §3.8's worked example: this card's channel avatar is already
  // accent-coloured, so DRAFT must render neutral, never warning — the
  // two must never sit together in one component.
  const badge = w.findAll('span').find((span) => span.text() === en.calendar.state.DRAFT)
  expect(badge?.classes().join(' ')).not.toMatch(/warning/)
  expect(badge?.classes().join(' ')).toMatch(/surface-2|muted/)
})

it('a non-draft card has a solid border, not dashed', () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ state: 'QUEUE' }), locked: false } })
  expect(w.classes()).not.toContain('border-dashed')
})

it('a locked (PUBLISHED) card shows a lock indicator instead of the remove button, and never emits remove', async () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ state: 'PUBLISHED' }), locked: true } })

  expect(w.find('svg').exists()).toBe(true)
  expect(w.find('button').exists()).toBe(false)
})

// Final review, Important 11: the lock icon carried `aria-hidden="true"`
// and `:aria-label` at once. aria-hidden wins, so the label was dead and
// the locked state — the one state that changes what the user can do —
// was announced nowhere. The previous assertion above passed on exactly
// that broken markup, because it only checked the attribute was present.
it('announces the locked state as text, since an aria-label on an aria-hidden icon is never read', () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ state: 'PUBLISHED' }), locked: true } })

  expect(w.text()).toContain(en.calendar.locked)

  // The lock glyph itself stays decorative; no icon in the card may carry
  // an aria-label, which is only ever dead weight under aria-hidden.
  const icons = w.findAll('svg')
  expect(icons.length).toBeGreaterThan(0)
  expect(icons.map((icon) => icon.attributes('aria-label'))).toEqual(icons.map(() => undefined))
})

it('an unlocked card exposes a remove button that emits remove with the group id', async () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ id: 'group-42' }), locked: false } })

  const button = w.find('button')
  expect(button.exists()).toBe(true)
  await button.trigger('click')

  expect(w.emitted('remove')).toEqual([['group-42']])
})
