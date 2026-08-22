import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const patch = vi.fn()
const del = vi.fn()
const post = vi.fn()

vi.mock('../../api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    patch: (...args: unknown[]) => patch(...args),
    delete: (...args: unknown[]) => del(...args),
    post: (...args: unknown[]) => post(...args),
  },
}))

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockClear()
  patch.mockClear()
  del.mockClear()
  post.mockClear()
})

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: 'group-1',
    state: 'QUEUE',
    publish_date: '2026-08-20T09:00:00.000000Z',
    share_token: null,
    tags: [],
    posts: [{ id: 'post-1', content: 'hello', state: 'QUEUE', integration: { id: 'int-1', provider_identifier: 'telegram', name: 'Chan' } }],
    ...overrides,
  }
}

it('fetchRange() requests /calendar with from/to and stores the returned entries', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry()] })

  const store = usePostsStore()
  await store.fetchRange('2026-08-01T00:00:00Z', '2026-08-31T23:59:59Z')

  expect(get).toHaveBeenCalledWith('/calendar', {
    params: { from: '2026-08-01T00:00:00Z', to: '2026-08-31T23:59:59Z', customer: undefined, tags: undefined },
  })
  expect(store.entries).toHaveLength(1)
  expect(store.entries[0].id).toBe('group-1')
})

it('moveTo() updates publish_date locally before the PATCH resolves, then adopts the server value on success', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry()] })

  const store = usePostsStore()
  await store.fetchRange('a', 'b')

  let resolvePatch: (value: unknown) => void = () => {}
  patch.mockReturnValueOnce(new Promise((resolve) => { resolvePatch = resolve }))

  const target = new Date('2026-08-22T12:00:00.000Z')
  const movePromise = store.moveTo('group-1', target)

  // Optimistic update is visible synchronously, before the PATCH settles.
  expect(store.entries[0].publish_date).toBe(target.toISOString())
  expect(store.entries[0].publish_date).not.toBe('2026-08-20T09:00:00.000000Z')

  resolvePatch({ data: entry({ publish_date: '2026-08-22T12:00:00.000000Z' }) })
  await movePromise

  expect(patch).toHaveBeenCalledWith('/posts/group-1/date', { publish_date: target.toISOString() })
  expect(store.entries[0].publish_date).toBe('2026-08-22T12:00:00.000000Z')
})

it('moveTo() rolls back to the exact original publish_date on a 409 (published mid-flight), without refetching', async () => {
  const { usePostsStore } = await import('../posts')
  const original = '2026-08-20T09:00:00.000000Z'
  get.mockResolvedValueOnce({ data: [entry({ publish_date: original })] })

  const store = usePostsStore()
  await store.fetchRange('a', 'b')
  get.mockClear()

  patch.mockRejectedValueOnce({ isAxiosError: true, response: { status: 409 } })

  await expect(store.moveTo('group-1', new Date('2026-08-25T00:00:00.000Z'))).rejects.toBeTruthy()

  expect(store.entries[0].publish_date).toBe(original)
  expect(get).not.toHaveBeenCalled()
})

it('moveTo() rolls back to the exact original publish_date on a 422 (past date rejected by validation)', async () => {
  const { usePostsStore } = await import('../posts')
  const original = '2026-08-20T09:00:00.000000Z'
  get.mockResolvedValueOnce({ data: [entry({ publish_date: original })] })

  const store = usePostsStore()
  await store.fetchRange('a', 'b')

  patch.mockRejectedValueOnce({ isAxiosError: true, response: { status: 422 } })

  await expect(store.moveTo('group-1', new Date('2020-01-01T00:00:00.000Z'))).rejects.toBeTruthy()

  expect(store.entries[0].publish_date).toBe(original)
})

it('moveTo() on an unknown group id is a no-op that resolves without calling the API', async () => {
  const { usePostsStore } = await import('../posts')
  const store = usePostsStore()

  await store.moveTo('missing', new Date())

  expect(patch).not.toHaveBeenCalled()
})

it('remove() optimistically splices the entry out, and restores it at its original index on failure', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry({ id: 'a' }), entry({ id: 'b' }), entry({ id: 'c' })] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')

  del.mockRejectedValueOnce({ isAxiosError: true, response: { status: 409 } })

  await expect(store.remove('b')).rejects.toBeTruthy()

  expect(store.entries.map((e) => e.id)).toEqual(['a', 'b', 'c'])
})

it('remove() keeps the entry out on success', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry({ id: 'a' }), entry({ id: 'b' })] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')

  del.mockResolvedValueOnce({})
  await store.remove('a')

  expect(store.entries.map((e) => e.id)).toEqual(['b'])
})

it('share() returns the server-built url and records the token it ends with', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry()] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')

  post.mockResolvedValueOnce({ data: { url: 'https://app.example/share/tok-123' } })

  const url = await store.share('group-1')

  expect(post).toHaveBeenCalledWith('/posts/group-1/share')
  expect(url).toBe('https://app.example/share/tok-123')
  expect(store.entries[0].share_token).toBe('tok-123')
})

it('share() still resolves for a group that is not in the current range', async () => {
  const { usePostsStore } = await import('../posts')
  const store = usePostsStore()

  post.mockResolvedValueOnce({ data: { url: 'https://app.example/share/tok-9' } })

  expect(await store.share('offscreen')).toBe('https://app.example/share/tok-9')
})

it('share() leaves the entry untouched when the request fails', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry()] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')

  post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 500 } })

  await expect(store.share('group-1')).rejects.toBeTruthy()

  expect(store.entries[0].share_token).toBeNull()
})

it('unshare() clears the token optimistically and restores it on failure', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry({ share_token: 'tok-123' })] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')

  let rejectDelete: (reason: unknown) => void = () => {}
  del.mockReturnValueOnce(new Promise((_resolve, reject) => { rejectDelete = reject }))

  const pending = store.unshare('group-1')

  // Cleared synchronously, before the DELETE settles.
  expect(store.entries[0].share_token).toBeNull()

  rejectDelete({ isAxiosError: true, response: { status: 500 } })
  await expect(pending).rejects.toBeTruthy()

  expect(del).toHaveBeenCalledWith('/posts/group-1/share')
  expect(store.entries[0].share_token).toBe('tok-123')
})

it('unshare() keeps the token cleared on success', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry({ share_token: 'tok-123' })] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')

  del.mockResolvedValueOnce({})
  await store.unshare('group-1')

  expect(store.entries[0].share_token).toBeNull()
})

it('unshare() on an unknown group id still calls the API, so a stale card can revoke', async () => {
  const { usePostsStore } = await import('../posts')
  const store = usePostsStore()

  del.mockResolvedValueOnce({})
  await store.unshare('offscreen')

  expect(del).toHaveBeenCalledWith('/posts/offscreen/share')
})

// Task 1.2. duplicate() returns only the new group id: the 201 body is a
// PostGroupResource, which carries neither the integration objects nor
// the tag names a CalendarEntry needs, so it cannot be spliced into
// `entries`. The page refetches the range instead.
it('duplicate() posts to the duplicate endpoint and returns the new group id', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry()] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')

  post.mockResolvedValueOnce({ data: { id: 'group-copy' } })
  const newId = await store.duplicate('group-1')

  expect(post).toHaveBeenCalledWith('/posts/group-1/duplicate')
  expect(newId).toBe('group-copy')
})

// A rejected duplicate must not half-touch the calendar: nothing was
// written locally, so there is nothing to roll back, and the error goes
// to the page, which decides whether to say anything (a 402 is already
// announced by the LimitToast the api client raises).
it('duplicate() re-throws and leaves entries untouched when the request fails', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce({ data: [entry()] })

  const store = usePostsStore()
  await store.fetchRange('x', 'y')
  const before = JSON.stringify(store.entries)

  post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 402 } })
  await expect(store.duplicate('group-1')).rejects.toBeTruthy()

  expect(JSON.stringify(store.entries)).toBe(before)
})
