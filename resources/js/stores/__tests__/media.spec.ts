import { createPinia, setActivePinia } from 'pinia'

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

it('remove() leaves items untouched and rethrows when the server 409s (media still in use)', async () => {
  const { useMediaStore } = await import('../media')
  const store = useMediaStore()
  store.$patch({ items: [mediaA, mediaB] })

  const conflict = Object.assign(new Error('conflict'), { response: { status: 409 } })
  del.mockRejectedValueOnce(conflict)

  await expect(store.remove('med-a')).rejects.toBe(conflict)

  expect(store.items).toEqual([mediaA, mediaB])
})
