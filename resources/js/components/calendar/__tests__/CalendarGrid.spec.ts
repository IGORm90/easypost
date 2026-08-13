import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { usePostsStore } from '../../../stores/posts'

const patch = vi.fn()
const del = vi.fn()

vi.mock('../../../api/client', () => ({
  api: {
    get: vi.fn(),
    patch: (...args: unknown[]) => patch(...args),
    delete: (...args: unknown[]) => del(...args),
  },
}))

// @fullcalendar/vue3 drives real drag/drop through the DOM, which
// happy-dom can't meaningfully simulate. Stubbing it out lets these
// specs assert on the actual `options` object CalendarGrid.vue builds
// (the `editable` flag per event, `eventAllow`, and the `eventDrop`
// handler it wires up) — the real logic under test — without needing a
// browser. `render: () => null` avoids needing Vue's runtime template
// compiler, which isn't guaranteed to be present in this build.
vi.mock('@fullcalendar/vue3', () => ({
  default: {
    name: 'FullCalendarStub',
    props: ['options'],
    render: () => null,
  },
}))

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: 'group-1',
    state: 'QUEUE',
    publish_date: '2026-08-20T09:00:00.000000Z',
    tags: [],
    posts: [{ id: 'post-1', content: 'hi', state: 'QUEUE', integration: { id: 'int-1', provider_identifier: 'telegram', name: 'Chan' } }],
    ...overrides,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  patch.mockClear()
  del.mockClear()
})

// CalendarGrid.vue reads `entries` from its prop for rendering, but
// `moveTo()`/`remove()` act on usePostsStore()'s OWN internal array — in
// the real app Calendar/Index.vue binds `:entries="postsStore.entries"`,
// the exact same reference, so both stay in lockstep. Mirror that here:
// seed the store with the same array passed as the prop.
async function mountGrid(entries: ReturnType<typeof entry>[]) {
  const { default: CalendarGrid } = await import('../CalendarGrid.vue')
  usePostsStore().$patch({ entries })

  return mount(CalendarGrid, { props: { entries, view: 'month', timezone: 'UTC' } })
}

function optionsOf(w: Awaited<ReturnType<typeof mountGrid>>) {
  return w.findComponent({ name: 'FullCalendarStub' }).props('options')
}

it('marks a PUBLISHED event as non-editable, so FullCalendar never starts a drag on it', async () => {
  const w = await mountGrid([entry({ state: 'PUBLISHED' })])

  expect(optionsOf(w).events[0].editable).toBe(false)
})

it('marks a group with any released segment as non-editable even when the group state itself is QUEUE (a partial release)', async () => {
  const partiallyReleased = entry({
    state: 'QUEUE',
    posts: [
      { id: 'p1', content: 'a', state: 'PUBLISHED', integration: { id: 'i1', provider_identifier: 'telegram', name: 'A' } },
      { id: 'p2', content: 'b', state: 'QUEUE', integration: { id: 'i2', provider_identifier: 'discord', name: 'B' } },
    ],
  })
  const w = await mountGrid([partiallyReleased])

  expect(optionsOf(w).events[0].editable).toBe(false)
})

it('leaves a QUEUE event editable', async () => {
  const w = await mountGrid([entry({ state: 'QUEUE' })])

  expect(optionsOf(w).events[0].editable).toBe(true)
})

it('eventAllow rejects dragging a locked event and allows an unlocked one', async () => {
  const w = await mountGrid([entry({ state: 'PUBLISHED' })])
  const options = optionsOf(w)

  const lockedFakeEvent = { extendedProps: { entry: entry({ state: 'PUBLISHED' }) } }
  const unlockedFakeEvent = { extendedProps: { entry: entry({ state: 'QUEUE' }) } }

  expect(options.eventAllow({}, lockedFakeEvent)).toBe(false)
  expect(options.eventAllow({}, unlockedFakeEvent)).toBe(true)
})

it('eventDrop on a locked event reverts immediately, never calls the API, and emits blocked', async () => {
  const w = await mountGrid([entry({ state: 'PUBLISHED' })])
  const options = optionsOf(w)

  const revert = vi.fn()
  const fakeInfo = {
    event: { id: 'group-1', start: new Date('2026-08-25T00:00:00.000Z'), extendedProps: { entry: entry({ state: 'PUBLISHED' }) } },
    revert,
  }

  await options.eventDrop(fakeInfo)

  expect(revert).toHaveBeenCalledOnce()
  expect(patch).not.toHaveBeenCalled()
  expect(w.emitted('blocked')).toEqual([[{ groupId: 'group-1' }]])
})

it('eventDrop on an unlocked event calls the store, then reverts and emits blocked with the 409 status on a publish race', async () => {
  patch.mockRejectedValueOnce({ isAxiosError: true, response: { status: 409 } })
  const w = await mountGrid([entry({ state: 'QUEUE' })])
  const options = optionsOf(w)

  const revert = vi.fn()
  const fakeInfo = {
    event: { id: 'group-1', start: new Date('2026-08-25T00:00:00.000Z'), extendedProps: { entry: entry({ state: 'QUEUE' }) } },
    revert,
  }

  await options.eventDrop(fakeInfo)

  expect(patch).toHaveBeenCalledWith('/posts/group-1/date', { publish_date: '2026-08-25T00:00:00.000Z' })
  expect(revert).toHaveBeenCalledOnce()
  expect(w.emitted('blocked')).toEqual([[{ groupId: 'group-1', status: 409 }]])
})

it('eventDrop on an unlocked event does not revert on success', async () => {
  patch.mockResolvedValueOnce({ data: entry({ publish_date: '2026-08-25T00:00:00.000000Z' }) })
  const w = await mountGrid([entry({ state: 'QUEUE' })])
  const options = optionsOf(w)

  const revert = vi.fn()
  const fakeInfo = {
    event: { id: 'group-1', start: new Date('2026-08-25T00:00:00.000Z'), extendedProps: { entry: entry({ state: 'QUEUE' }) } },
    revert,
  }

  await options.eventDrop(fakeInfo)

  expect(revert).not.toHaveBeenCalled()
  expect(w.emitted('blocked')).toBeFalsy()
})
