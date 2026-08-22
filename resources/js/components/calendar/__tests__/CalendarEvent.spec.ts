import { mount } from '@vue/test-utils'
import CalendarEvent from '../CalendarEvent.vue'
import PostActionsMenu from '../PostActionsMenu.vue'
import en from '../../../i18n/en.json'

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: 'group-1',
    state: 'QUEUE',
    publish_date: '2026-08-20T09:00:00.000000Z',
    share_token: null,
    repeats: false,
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

// Task 1.1 rewrote this: a locked card is no longer button-less. Sharing
// a PUBLISHED post is the whole point of the share link, so the actions
// menu stays — what the lock forbids is editing and deleting, and only
// "Remove" disappears from the menu.
it('a locked (PUBLISHED) card shows a lock indicator and offers Share but not Remove', async () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ state: 'PUBLISHED' }), locked: true } })

  expect(w.find('svg').exists()).toBe(true)

  await w.get('button[aria-haspopup="menu"]').trigger('click')
  const labels = w.findAll('[role="menuitem"]').map((i) => i.text())

  expect(labels).toContain(en.calendar.share.menuItem)
  expect(labels).not.toContain(en.calendar.remove)
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

it('an unlocked card emits remove with the group id when Remove is chosen', async () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ id: 'group-42' }), locked: false } })

  await w.get('button[aria-haspopup="menu"]').trigger('click')
  const remove = w.findAll('[role="menuitem"]').find((i) => i.text() === en.calendar.remove)
  await remove?.trigger('click')

  expect(w.emitted('remove')).toEqual([['group-42']])
})

it('emits share with the group id when Share is chosen', async () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ id: 'group-42' }), locked: false } })

  await w.get('button[aria-haspopup="menu"]').trigger('click')
  const share = w.findAll('[role="menuitem"]').find((i) => i.text() === en.calendar.share.menuItem)
  await share?.trigger('click')

  expect(w.emitted('share')).toEqual([['group-42']])
})

// color.md §4: an active public link is a state the owner needs to be
// able to see at a glance, and it may not be conveyed by colour alone —
// same treatment the lock already gets (icon + real text).
it('shows the active-link state as text beside an icon, not as colour', () => {
  const shared = mount(CalendarEvent, { props: { entry: entry({ share_token: 'tok-1' }), locked: false } })
  const plain = mount(CalendarEvent, { props: { entry: entry(), locked: false } })

  expect(shared.text()).toContain(en.calendar.share.indicator)
  expect(plain.text()).not.toContain(en.calendar.share.indicator)
  expect(shared.findAll('svg').length).toBeGreaterThan(plain.findAll('svg').length)
})

// Task 1.2, the hazard found while adding a third menu item: onMenuSelect
// used to be a fall-through — anything that was not 'share' emitted
// 'remove'. That made every future menu entry a delete button by default,
// and the failure mode is the worst one available here (a post silently
// disappears). Dispatch is now explicit, and an unrecognised key does
// nothing at all.
it('an unrecognised menu key emits nothing, least of all remove', async () => {
  const w = mount(CalendarEvent, { props: { entry: entry(), locked: false } })

  w.findComponent(PostActionsMenu).vm.$emit('select', 'not-a-real-action')
  await w.vm.$nextTick()

  expect(w.emitted('remove')).toBeUndefined()
  expect(w.emitted('share')).toBeUndefined()
})

// Task 1.2. Duplicating produces a fresh DRAFT group and leaves the
// original untouched, so — unlike Remove — the item stays on a locked
// (PUBLISHED) card. Repeating a post that already published well is in
// fact the main reason to duplicate at all.
it('offers Duplicate on both an unlocked and a locked card', async () => {
  for (const locked of [false, true]) {
    const w = mount(CalendarEvent, { props: { entry: entry({ state: locked ? 'PUBLISHED' : 'QUEUE' }), locked } })

    await w.get('button[aria-haspopup="menu"]').trigger('click')
    const labels = w.findAll('[role="menuitem"]').map((i) => i.text())

    expect(labels).toContain(en.calendar.duplicate.menuItem)
  }
})

it('emits duplicate — and never remove — with the group id when Duplicate is chosen', async () => {
  const w = mount(CalendarEvent, { props: { entry: entry({ id: 'group-42' }), locked: false } })

  await w.get('button[aria-haspopup="menu"]').trigger('click')
  const duplicate = w.findAll('[role="menuitem"]').find((i) => i.text() === en.calendar.duplicate.menuItem)
  await duplicate?.trigger('click')

  expect(w.emitted('duplicate')).toEqual([['group-42']])
  expect(w.emitted('remove')).toBeUndefined()
})

// Block 4: same rule as the active-link indicator above — icon plus real
// text, so "this one repeats" survives a colourblind reader and a
// grayscale print alike (color.md §4).
it('shows a repeating post as text beside an icon, not as colour', () => {
  const repeating = mount(CalendarEvent, { props: { entry: entry({ repeats: true }), locked: false } })
  const plain = mount(CalendarEvent, { props: { entry: entry(), locked: false } })

  expect(repeating.text()).toContain(en.calendar.repeatIndicator)
  expect(plain.text()).not.toContain(en.calendar.repeatIndicator)
  expect(repeating.findAll('svg').length).toBeGreaterThan(plain.findAll('svg').length)
})
