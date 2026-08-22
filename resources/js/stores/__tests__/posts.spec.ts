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

/*
 * Block 2's list view. It is a second collection in the same store — a
 * different response shape (PostGroupListResource: `preview`/`channels`/
 * `comments_count`, no nested segment content), so it cannot share
 * `entries` — which makes the mutations below the interesting part: they
 * used to look the group up in `entries` alone, so a row acted on from
 * the list issued its request and then sat there unchanged.
 */
function listRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 'group-1',
    state: 'QUEUE',
    publish_date: '2026-08-20T09:00:00.000000Z',
    share_token: null,
    preview: 'hello',
    channels: [{ id: 'int-1', provider_identifier: 'telegram', name: 'Chan', picture: null }],
    tags: [],
    comments_count: 0,
    ...overrides,
  }
}

function page(rows: unknown[], current = 1, last = 1) {
  return { data: { data: rows, meta: { current_page: current, last_page: last } } }
}

it('fetchList() sends the state/tag/customer filters and the next page number', async () => {
  const { usePostsStore } = await import('../posts')
  get.mockResolvedValueOnce(page([listRow()]))

  const store = usePostsStore()
  store.listFilters = { state: 'ERROR', customer: 'Acme', tags: ['t-1'] }
  await store.fetchList({ reset: true })

  expect(get).toHaveBeenCalledWith('/posts', {
    params: { page: 1, state: 'ERROR', customer: 'Acme', tags: ['t-1'] },
  })
  expect(store.list.map((row) => row.id)).toEqual(['group-1'])
})

it('fetchList() appends the next page and stops when the last one is reached', async () => {
  const { usePostsStore } = await import('../posts')
  const store = usePostsStore()

  get.mockResolvedValueOnce(page([listRow({ id: 'a' })], 1, 2))
  await store.fetchList({ reset: true })
  expect(store.listHasMore).toBe(true)

  get.mockResolvedValueOnce(page([listRow({ id: 'b' })], 2, 2))
  await store.fetchList()

  expect(store.list.map((row) => row.id)).toEqual(['a', 'b'])
  expect(store.listHasMore).toBe(false)

  // Exhausted: a further call must not issue a request for page 3.
  get.mockClear()
  await store.fetchList()
  expect(get).not.toHaveBeenCalled()
})

it('fetchList({ reset: true }) throws away what a previous filter accumulated', async () => {
  const { usePostsStore } = await import('../posts')
  const store = usePostsStore()

  get.mockResolvedValueOnce(page([listRow({ id: 'a' })], 1, 2))
  await store.fetchList({ reset: true })

  get.mockResolvedValueOnce(page([listRow({ id: 'z' })], 1, 1))
  await store.fetchList({ reset: true })

  expect(store.list.map((row) => row.id)).toEqual(['z'])
  expect(get).toHaveBeenLastCalledWith('/posts', { params: { page: 1, state: undefined, customer: undefined, tags: undefined } })
})

it('remove() takes the row out of the list too, and puts it back at its index on failure', async () => {
  const { usePostsStore } = await import('../posts')
  const store = usePostsStore()

  get.mockResolvedValueOnce(page([listRow({ id: 'a' }), listRow({ id: 'b' }), listRow({ id: 'c' })]))
  await store.fetchList({ reset: true })

  del.mockResolvedValueOnce({})
  await store.remove('b')
  expect(store.list.map((row) => row.id)).toEqual(['a', 'c'])

  del.mockRejectedValueOnce({ isAxiosError: true, response: { status: 409 } })
  await expect(store.remove('a')).rejects.toBeTruthy()
  expect(store.list.map((row) => row.id)).toEqual(['a', 'c'])
})

it('share()/unshare() keep the list row indicator in step', async () => {
  const { usePostsStore } = await import('../posts')
  const store = usePostsStore()

  get.mockResolvedValueOnce(page([listRow()]))
  await store.fetchList({ reset: true })

  post.mockResolvedValueOnce({ data: { url: 'https://app.example/share/tok-77' } })
  await store.share('group-1')
  expect(store.list[0].share_token).toBe('tok-77')

  del.mockResolvedValueOnce({})
  await store.unshare('group-1')
  expect(store.list[0].share_token).toBeNull()
})
