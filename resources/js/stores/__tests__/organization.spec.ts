import { createPinia, setActivePinia } from 'pinia'

const post = vi.fn()
const get = vi.fn()

vi.mock('../../api/client', () => ({
  api: { post: (...args: unknown[]) => post(...args), get: (...args: unknown[]) => get(...args) },
}))

vi.mock('../../router', () => ({ default: { push: vi.fn() } }))

beforeEach(() => {
  setActivePinia(createPinia())
  post.mockReset()
  get.mockReset()
  localStorage.clear()
})

it('creates and selects a workspace without refetching the session', async () => {
  const { useOrganizationStore } = await import('../organization')
  const { useAuthStore } = await import('../auth')
  const old = { id: 'a', name: 'A', slug: 'a', created_at: '', updated_at: '' }
  const created = { id: 'b', name: 'B', slug: 'b', created_at: '', updated_at: '' }
  const auth = useAuthStore()
  auth.organizations = [old]
  const store = useOrganizationStore()
  store.syncFromAuth()
  post.mockResolvedValueOnce({ data: created })

  expect(await store.create('B')).toEqual(created)

  expect(post).toHaveBeenCalledExactlyOnceWith('/organizations', { name: 'B' })
  expect(auth.organizations).toEqual([old, created])
  expect(store.current).toEqual(created)
  expect(localStorage.getItem('organization_id')).toBe('b')
  expect(get).not.toHaveBeenCalled()
  store.syncFromAuth()
  expect(store.current).toEqual(created)
})

it('creates and selects the first workspace when the membership list is empty', async () => {
  const { useOrganizationStore } = await import('../organization')
  const { useAuthStore } = await import('../auth')
  const created = { id: 'a', name: 'A', slug: 'a', created_at: '', updated_at: '' }
  const auth = useAuthStore()
  const store = useOrganizationStore()
  post.mockResolvedValueOnce({ data: created })

  await store.create('A')

  expect(auth.organizations).toEqual([created])
  expect(store.current).toEqual(created)
  expect(localStorage.getItem('organization_id')).toBe('a')
  expect(get).not.toHaveBeenCalled()
})

it('preserves the current workspace and membership list when creation fails', async () => {
  const { useOrganizationStore } = await import('../organization')
  const { useAuthStore } = await import('../auth')
  const old = { id: 'a', name: 'A', slug: 'a', created_at: '', updated_at: '' }
  const auth = useAuthStore()
  auth.organizations = [old]
  const store = useOrganizationStore()
  store.syncFromAuth()
  const error = new Error('Network error')
  post.mockRejectedValueOnce(error)

  await expect(store.create('B')).rejects.toBe(error)

  expect(auth.organizations).toEqual([old])
  expect(store.current).toEqual(old)
  expect(localStorage.getItem('organization_id')).toBe('a')
  expect(get).not.toHaveBeenCalled()
})

it('updates an existing membership instead of duplicating a returned workspace', async () => {
  const { useOrganizationStore } = await import('../organization')
  const { useAuthStore } = await import('../auth')
  const old = { id: 'a', name: 'A', slug: 'a', created_at: '', updated_at: '' }
  const created = { id: 'b', name: 'B', slug: 'b', created_at: '', updated_at: '' }
  const auth = useAuthStore()
  auth.organizations = [old, { ...created, name: 'Earlier name' }]
  const store = useOrganizationStore()
  post.mockResolvedValueOnce({ data: created })

  await store.create('B')

  expect(auth.organizations).toEqual([old, created])
  expect(store.current).toEqual(created)
  expect(localStorage.getItem('organization_id')).toBe('b')
})

it('switchTo() persists the chosen id to localStorage, which api/client.ts reads for the X-Organization header', async () => {
  const { useOrganizationStore } = await import('../organization')
  const orgB = { id: 'org-b', name: 'B', slug: 'b', created_at: '', updated_at: '' }
  get.mockResolvedValueOnce({ data: { user: { id: 'u1' }, organizations: [{ id: 'org-a' }, orgB] } })

  const store = useOrganizationStore()
  await store.switchTo('org-b')

  expect(localStorage.getItem('organization_id')).toBe('org-b')
  expect(store.current).toEqual(orgB)
})

it('switchTo() refreshes the auth store (dependent data) so organizations/user reflect the new tenant', async () => {
  const { useOrganizationStore } = await import('../organization')
  const { useAuthStore } = await import('../auth')
  get.mockResolvedValueOnce({ data: { user: { id: 'u1' }, organizations: [{ id: 'org-a' }] } })

  const orgStore = useOrganizationStore()
  await orgStore.switchTo('org-a')

  expect(get).toHaveBeenCalledWith('/auth/me')
  const authStore = useAuthStore()
  expect(authStore.organizations).toEqual([{ id: 'org-a' }])
})

it('syncFromAuth() falls back to the first membership when the stored id no longer applies', async () => {
  const { useOrganizationStore } = await import('../organization')
  const { useAuthStore } = await import('../auth')
  localStorage.setItem('organization_id', 'stale-org')

  const authStore = useAuthStore()
  const orgA = { id: 'org-a', name: 'A', slug: 'a', created_at: '', updated_at: '' }
  authStore.$patch({ organizations: [orgA] })

  const orgStore = useOrganizationStore()
  orgStore.syncFromAuth()

  expect(orgStore.current).toEqual(orgA)
  expect(localStorage.getItem('organization_id')).toBe('org-a')
})

it('switchTo() to an id no longer among the caller\'s memberships clears current rather than keeping a stale org', async () => {
  const { useOrganizationStore } = await import('../organization')
  get.mockResolvedValueOnce({ data: { user: { id: 'u1' }, organizations: [{ id: 'org-a' }] } })

  const store = useOrganizationStore()
  await store.switchTo('org-gone')

  expect(store.current).toBeNull()
})
