import { createPinia, setActivePinia } from 'pinia'
import { effectScope, watchEffect } from 'vue'

const get = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('../../api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
  },
}))

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockClear()
  post.mockClear()
  del.mockClear()
})

const mediaA = {
  id: 'med-a',
  url: 'https://cdn.example.com/a.jpg',
  thumbnail_url: 'https://cdn.example.com/a-thumb.jpg',
  mime: 'image/jpeg',
  size_bytes: 1024,
  width: 800,
  height: 600,
  original_filename: 'a.jpg',
  created_at: '2026-08-01T00:00:00Z',
}

const mediaB = { ...mediaA, id: 'med-b', original_filename: 'b.jpg' }

function fakeFile(name: string, type: string, size = 100): File {
  return new File([new Uint8Array(size)], name, { type })
}

it('fetch() populates items from GET /media, page 1 by default', async () => {
  const { useMediaStore } = await import('../media')
  get.mockResolvedValueOnce({ data: { data: [mediaA, mediaB], meta: { current_page: 1, last_page: 2 } } })

  const store = useMediaStore()
  expect(store.items).toEqual([])

  await store.fetch()

  expect(get).toHaveBeenCalledWith('/media', { params: { page: 1, search: undefined } })
  expect(store.items).toEqual([mediaA, mediaB])
  expect(store.page).toBe(1)
  expect(store.hasMore).toBe(true)
})

it('fetch() appends the next page rather than replacing items', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA], page: 1, hasMore: true })

  get.mockResolvedValueOnce({ data: { data: [mediaB], meta: { current_page: 2, last_page: 2 } } })

  await store.fetch()

  expect(get).toHaveBeenCalledWith('/media', { params: { page: 2, search: undefined } })
  expect(store.items).toEqual([mediaA, mediaB])
  expect(store.hasMore).toBe(false)
})

it('fetch() does nothing once hasMore is false', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA], page: 1, hasMore: false })

  await store.fetch()

  expect(get).not.toHaveBeenCalled()
})

it('fetch({reset: true}) clears items and restarts from page 1', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA], page: 1, hasMore: false })

  get.mockResolvedValueOnce({ data: { data: [mediaB], meta: { current_page: 1, last_page: 1 } } })

  await store.fetch({ reset: true })

  expect(get).toHaveBeenCalledWith('/media', { params: { page: 1, search: undefined } })
  expect(store.items).toEqual([mediaB])
})

it('setSearch() changes the term fetch() sends', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.setSearch('logo')

  get.mockResolvedValueOnce({ data: { data: [mediaA], meta: { current_page: 1, last_page: 1 } } })

  await store.fetch({ reset: true })

  expect(get).toHaveBeenCalledWith('/media', { params: { page: 1, search: 'logo' } })
})

it('upload() adds a placeholder to the front of items immediately, then replaces it with the server response', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA] })

  let resolveUpload!: (value: unknown) => void
  post.mockReturnValueOnce(new Promise((resolve) => { resolveUpload = resolve }))

  const file = fakeFile('c.jpg', 'image/jpeg')
  const upload = store.upload(file)

  // Placeholder is visible synchronously, before the request resolves.
  expect(store.items).toHaveLength(2)
  expect(store.items[0].id).not.toBe('med-a')
  expect(store.items[0].pending).toBe(true)
  expect(store.items[0].original_filename).toBe('c.jpg')
  expect(store.items[1]).toEqual(mediaA)

  resolveUpload({ data: { data: { ...mediaA, id: 'med-c', original_filename: 'c.jpg' } } })
  await upload

  expect(store.items).toHaveLength(2)
  expect(store.items[0]).toEqual({ ...mediaA, id: 'med-c', original_filename: 'c.jpg' })
  expect(store.items[0].pending).toBeUndefined()
})

it('upload() posts a multipart FormData carrying the file to /media', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()

  post.mockResolvedValueOnce({ data: { data: { ...mediaA, id: 'med-c' } } })

  const file = fakeFile('c.jpg', 'image/jpeg')
  await store.upload(file)

  expect(post).toHaveBeenCalledTimes(1)
  const [url, body] = post.mock.calls[0]
  expect(url).toBe('/media')
  expect(body).toBeInstanceOf(FormData)
  expect(body.get('file')).toBe(file)
})

it('upload() reports progress through onProgress as axios reports it', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()

  post.mockImplementationOnce((_url: string, _body: FormData, config: { onUploadProgress?: (e: { loaded: number; total: number }) => void }) => {
    config.onUploadProgress?.({ loaded: 50, total: 100 })
    return Promise.resolve({ data: { data: { ...mediaA, id: 'med-c' } } })
  })

  const progress = vi.fn()
  await store.upload(fakeFile('c.jpg', 'image/jpeg'), progress)

  expect(progress).toHaveBeenCalledWith(50)
})

it('upload() progress is visible on store.items mid-flight, not just passed to the onProgress callback — review finding 1 (reactivity harness)', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()

  let reportProgress!: (loaded: number, total: number) => void
  post.mockImplementationOnce((_url: string, _body: FormData, config: { onUploadProgress?: (e: { loaded: number; total: number }) => void }) => {
    reportProgress = (loaded, total) => config.onUploadProgress?.({ loaded, total })
    return new Promise(() => {}) // never resolves — we only care about the mid-flight state
  })

  // A real reactivity harness: an effect that records every value
  // `store.items[0].progress` takes on, driven purely by Vue's own
  // dependency tracking — NOT a manual re-read after the fact, which
  // would pass even if the write bypassed the reactive proxy entirely
  // (exactly the bug this test exists to catch: mutating the raw
  // object captured in upload()'s closure changes the data but fires
  // no trigger, so an effect subscribed to it would never re-run).
  const observed: Array<number | undefined> = []
  const scope = effectScope()
  scope.run(() => {
    watchEffect(() => {
      observed.push(store.items[0]?.progress)
    })
  })

  void store.upload(fakeFile('video.mp4', 'video/mp4'))
  reportProgress(25, 100)
  await Promise.resolve()
  reportProgress(75, 100)
  await Promise.resolve()

  scope.stop()

  expect(observed).toContain(25)
  expect(observed).toContain(75)
  expect(store.items[0].progress).toBe(75)
})

it('upload() leaves no phantom item when the request fails', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA] })

  post.mockRejectedValueOnce(new Error('413 too large'))

  await expect(store.upload(fakeFile('huge.mp4', 'video/mp4'))).rejects.toThrow('413 too large')

  // Rolled back cleanly: only the pre-existing item remains.
  expect(store.items).toEqual([mediaA])
})

it('remove() deletes on the server then drops the item locally', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA, mediaB] })

  del.mockResolvedValueOnce({})

  await store.remove('med-a')

  expect(del).toHaveBeenCalledWith('/media/med-a')
  expect(store.items).toEqual([mediaB])
})

it('hydrate() upserts media by id without disturbing existing entries', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA] })

  const mediaARenamed = { ...mediaA, original_filename: 'renamed-a.jpg' }
  store.hydrate([mediaARenamed, mediaB])

  expect(store.items).toEqual([mediaARenamed, mediaB])
})

it('hydrate() gives a brand-new id sane defaults for fields PostResource never sends — never undefined', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()

  // The exact narrower shape PostResource's `posts[].value[].media`
  // sends (Task 28/review finding 4) — no original_filename/size_bytes/
  // created_at at all.
  store.hydrate([{ id: 'med-new', url: 'https://cdn.example.com/new.jpg', thumbnail_url: null, mime: 'image/png', width: 100, height: 100 }])

  const item = store.items[0]
  expect(item.original_filename).toBe('') // never `undefined` — see MediaTile's `:alt`
  expect(item.size_bytes).toBe(0)
  expect(item.created_at).toBe('')
})

it("hydrate() never clobbers an already-fully-known item's original_filename/size_bytes/created_at with the narrower shape's absence of them", async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA] }) // full record, from a prior fetch()

  store.hydrate([{ id: 'med-a', url: mediaA.url, thumbnail_url: mediaA.thumbnail_url, mime: mediaA.mime, width: mediaA.width, height: mediaA.height }])

  expect(store.items[0].original_filename).toBe('a.jpg')
  expect(store.items[0].size_bytes).toBe(1024)
  expect(store.items[0].created_at).toBe('2026-08-01T00:00:00Z')
})

it('fetch() upserts by id — a page that re-covers an id hydrate() already stubbed in replaces it instead of duplicating', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()

  store.hydrate([{ id: 'med-a', url: mediaA.url, thumbnail_url: mediaA.thumbnail_url, mime: mediaA.mime, width: mediaA.width, height: mediaA.height }])
  expect(store.items).toHaveLength(1)

  get.mockResolvedValueOnce({ data: { data: [mediaA, mediaB], meta: { current_page: 1, last_page: 1 } } })
  await store.fetch()

  // Not 3 (a duplicate med-a plus med-b) — the full fetch() record for
  // med-a replaces the hydrate() stub in place.
  expect(store.items).toHaveLength(2)
  expect(store.items.filter((item) => item.id === 'med-a')).toHaveLength(1)
  expect(store.items.find((item) => item.id === 'med-a')).toEqual(mediaA)
})

it('remove() leaves items untouched and rethrows when the server 409s (media still in use)', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA, mediaB] })

  const conflict = Object.assign(new Error('conflict'), { response: { status: 409 } })
  del.mockRejectedValueOnce(conflict)

  await expect(store.remove('med-a')).rejects.toBe(conflict)

  expect(store.items).toEqual([mediaA, mediaB])
})
