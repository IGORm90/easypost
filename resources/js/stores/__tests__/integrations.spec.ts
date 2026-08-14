import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()

vi.mock('../../api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    delete: (...args: unknown[]) => del(...args),
  },
}))

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockClear()
  post.mockClear()
  put.mockClear()
  del.mockClear()
})

const channelA = {
  id: 'int-a',
  provider_identifier: 'telegram',
  name: 'Announcements',
  picture: null,
  profile: null,
  settings: {},
  customer_name: null,
  group_name: null,
  disabled: false,
  refresh_needed: false,
  time_slots: [],
  created_at: '',
  updated_at: '',
}

const channelB = { ...channelA, id: 'int-b', name: 'Support' }

it('fetch() populates list from GET /integrations', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  get.mockResolvedValueOnce({ data: [channelA, channelB] })

  const store = useIntegrationsStore()
  expect(store.list).toEqual([])

  await store.fetch()

  expect(get).toHaveBeenCalledWith('/integrations')
  expect(store.list).toEqual([channelA, channelB])
})

it('remove() removes the channel optimistically, before the DELETE request resolves', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  store.$patch({ list: [channelA, channelB] })

  let resolveDelete!: () => void
  del.mockReturnValueOnce(new Promise<void>((resolve) => { resolveDelete = resolve }))

  const removal = store.remove('int-a')

  // Gone from `list` immediately — before the in-flight DELETE settles.
  expect(store.list).toEqual([channelB])

  resolveDelete()
  await removal

  expect(del).toHaveBeenCalledWith('/integrations/int-a')
  expect(store.list).toEqual([channelB])
})

it('remove() rolls back the optimistic removal when the DELETE request fails', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  store.$patch({ list: [channelA, channelB] })

  del.mockRejectedValueOnce(new Error('network blip'))

  await expect(store.remove('int-a')).rejects.toThrow('network blip')

  // Restored — original order and content both, not just "back in some form".
  expect(store.list).toEqual([channelA, channelB])
})

it('remove() rollback restores the channel to its original position, not appended at the end', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  const channelC = { ...channelA, id: 'int-c', name: 'Community' }
  store.$patch({ list: [channelA, channelB, channelC] })

  del.mockRejectedValueOnce(new Error('boom'))

  await expect(store.remove('int-b')).rejects.toThrow('boom')

  expect(store.list.map((c) => c.id)).toEqual(['int-a', 'int-b', 'int-c'])
})

it('fetchProviders() populates providers from GET /providers', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const providers = [{ identifier: 'telegram', name: 'Telegram', capabilities: {}, auth_type: 'manual' }]
  get.mockResolvedValueOnce({ data: providers })

  const store = useIntegrationsStore()
  await store.fetchProviders()

  expect(get).toHaveBeenCalledWith('/providers')
  expect(store.providers).toEqual(providers)
})

it('connect() requests an auth-url/state for the given provider identifier, generically', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  post.mockResolvedValueOnce({ data: { url: 'https://mastodon.social/oauth/authorize', state: 's1', extra: { instance: 'mastodon.social' } } })

  const store = useIntegrationsStore()
  const result = await store.connect('mastodon', { instance: 'mastodon.social' })

  expect(post).toHaveBeenCalledWith('/integrations/mastodon/auth-url', { instance: 'mastodon.social' })
  expect(result).toEqual({ url: 'https://mastodon.social/oauth/authorize', state: 's1', extra: { instance: 'mastodon.social' } })
})

it('completeCallback() posts to the provider callback and adds the returned channel to list', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  post.mockResolvedValueOnce({ data: channelA })

  const store = useIntegrationsStore()
  const integration = await store.completeCallback('telegram', { state: 's1', bot_token: 't', chat_id: '1' })

  expect(post).toHaveBeenCalledWith('/integrations/telegram/callback', { state: 's1', bot_token: 't', chat_id: '1' })
  expect(integration).toEqual(channelA)
  expect(store.list).toEqual([channelA])
})

it('completeCallback() replaces an existing entry instead of duplicating it (reconnect case)', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  store.$patch({ list: [channelA] })

  const reconnected = { ...channelA, refresh_needed: false, disabled: false, name: 'Announcements (reconnected)' }
  post.mockResolvedValueOnce({ data: reconnected })

  await store.completeCallback('telegram', { state: 's2' })

  expect(store.list).toEqual([reconnected])
})

it('update() sends a PUT and replaces the channel in list with the response', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  store.$patch({ list: [channelA] })

  const updated = { ...channelA, name: 'Renamed', time_slots: [0, 60] }
  put.mockResolvedValueOnce({ data: updated })

  await store.update('int-a', { name: 'Renamed', time_slots: [0, 60] })

  expect(put).toHaveBeenCalledWith('/integrations/int-a', { name: 'Renamed', time_slots: [0, 60] })
  expect(store.list).toEqual([updated])
})

// Final review, Critical 2: `time_slots` is whenLoaded() server-side, so
// any endpoint that forgets to load the relation returns an object with
// the key missing rather than empty. Clobbering the held channel with
// that object makes the settings drawer render an empty slot grid, and
// the next Save wipes the real schedule. The server side is fixed, but
// the store must not be the single point of failure for it either.
it('replace() keeps the time_slots it already holds when the incoming payload omits the key', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  store.$patch({ list: [{ ...channelA, time_slots: [540, 1980] }] })

  const withoutSlots: Record<string, unknown> = { ...channelA, disabled: true }
  delete withoutSlots.time_slots

  store.replace(withoutSlots as never)

  expect(store.list[0].disabled).toBe(true)
  expect(store.list[0].time_slots).toEqual([540, 1980])
})

it('replace() still applies an explicitly empty time_slots — clearing a schedule is a real outcome', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  store.$patch({ list: [{ ...channelA, time_slots: [540] }] })

  store.replace({ ...channelA, time_slots: [] })

  expect(store.list[0].time_slots).toEqual([])
})

it('toggle() disables an enabled channel', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  store.$patch({ list: [channelA] })

  post.mockResolvedValueOnce({ data: { ...channelA, disabled: true } })

  await store.toggle('int-a')

  expect(post).toHaveBeenCalledWith('/integrations/int-a/disable')
  expect(store.list[0].disabled).toBe(true)
})

it('toggle() enables a disabled channel', async () => {
  const { useIntegrationsStore } = await import('../integrations')
  const store = useIntegrationsStore()
  const disabledChannel = { ...channelA, disabled: true }
  store.$patch({ list: [disabledChannel] })

  post.mockResolvedValueOnce({ data: { ...disabledChannel, disabled: false } })

  await store.toggle('int-a')

  expect(post).toHaveBeenCalledWith('/integrations/int-a/enable')
  expect(store.list[0].disabled).toBe(false)
})
