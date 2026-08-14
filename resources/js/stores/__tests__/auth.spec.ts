import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '../../i18n'

const push = vi.fn()

vi.mock('../../router', () => ({
  default: { push },
}))

const post = vi.fn()
const get = vi.fn()

vi.mock('../../api/client', () => ({
  api: { post: (...args: unknown[]) => post(...args), get: (...args: unknown[]) => get(...args) },
}))

beforeEach(() => {
  setActivePinia(createPinia())
  push.mockClear()
  post.mockClear()
  get.mockClear()
  i18n.global.locale.value = 'en'
})

// Final review, Important 7: detectLocale() reads navigator.language and
// nothing else, and the stored profile locale was applied only at the
// moment the profile form was saved. A user on an English-locale browser
// who chose Russian got English back on the very next page load, forever.
it('applies the user\'s stored locale on login', async () => {
  const { useAuthStore } = await import('../auth')
  const user = { id: '01', name: 'Ann', email: 'ann@example.com', timezone: 'UTC', locale: 'ru', email_verified_at: null, created_at: '', updated_at: '' }
  post.mockResolvedValueOnce({ data: { user, organizations: [] } })

  await useAuthStore().login('ann@example.com', 'secret1234')

  expect(i18n.global.locale.value).toBe('ru')
})

it('applies the user\'s stored locale on fetchMe', async () => {
  const { useAuthStore } = await import('../auth')
  const user = { id: '01', name: 'Ann', email: 'ann@example.com', timezone: 'UTC', locale: 'ru', email_verified_at: null, created_at: '', updated_at: '' }
  get.mockResolvedValueOnce({ data: { user, organizations: [] } })

  await useAuthStore().fetchMe()

  expect(i18n.global.locale.value).toBe('ru')
})

it('ignores a locale this build has no messages for, rather than blanking the UI', async () => {
  const { useAuthStore } = await import('../auth')
  const user = { id: '01', name: 'Ann', email: 'ann@example.com', timezone: 'UTC', locale: 'de', email_verified_at: null, created_at: '', updated_at: '' }
  get.mockResolvedValueOnce({ data: { user, organizations: [] } })

  await useAuthStore().fetchMe()

  expect(i18n.global.locale.value).toBe('en')
})

it('login() populates user AND organizations from the response', async () => {
  const { useAuthStore } = await import('../auth')
  const user = { id: '01', name: 'Ann', email: 'ann@example.com', timezone: 'UTC', locale: 'en', email_verified_at: null, created_at: '', updated_at: '' }
  const organizations = [{ id: 'org-1', name: 'Acme', slug: 'acme', created_at: '', updated_at: '' }]
  post.mockResolvedValueOnce({ data: { user, organizations } })

  const store = useAuthStore()
  await store.login('ann@example.com', 'secret1234')

  expect(post).toHaveBeenCalledWith('/auth/login', { email: 'ann@example.com', password: 'secret1234' })
  expect(store.user).toEqual(user)
  expect(store.ready).toBe(true)
  // Final review, Critical 1: login() marks the store `ready`, so the
  // router guard skips fetchMe() on the first navigation and the
  // organization store syncs against exactly this array. Leaving it empty
  // dropped `organization_id` and 403'd every request on the calendar the
  // user had just been sent to.
  expect(store.organizations).toEqual(organizations)
})

it('logout() clears the user and redirects to /login', async () => {
  const { useAuthStore } = await import('../auth')
  post.mockResolvedValueOnce({ data: { user: { id: '01' } } })
  const store = useAuthStore()
  await store.login('ann@example.com', 'secret1234')

  post.mockResolvedValueOnce({ data: null })
  await store.logout()

  expect(store.user).toBeNull()
  expect(store.organizations).toEqual([])
  expect(push).toHaveBeenCalledWith({ name: 'login' })
})

it('logout() still clears local state and redirects even when the server call fails', async () => {
  const { useAuthStore } = await import('../auth')
  const store = useAuthStore()
  store.$patch({ user: { id: '01' } as never })

  post.mockRejectedValueOnce(new Error('network blip'))
  await store.logout()

  expect(store.user).toBeNull()
  expect(push).toHaveBeenCalledWith({ name: 'login' })
})

it('fetchMe() populates user and organizations on success', async () => {
  const { useAuthStore } = await import('../auth')
  const user = { id: '01', name: 'Ann' }
  const organizations = [{ id: 'org1', name: 'Acme' }]
  get.mockResolvedValueOnce({ data: { user, organizations } })

  const store = useAuthStore()
  await store.fetchMe()

  expect(store.user).toEqual(user)
  expect(store.organizations).toEqual(organizations)
  expect(store.ready).toBe(true)
})

it('fetchMe() clears user on a failed (e.g. 401) request instead of throwing', async () => {
  const { useAuthStore } = await import('../auth')
  get.mockRejectedValueOnce(new Error('401'))

  const store = useAuthStore()
  await expect(store.fetchMe()).resolves.toBeUndefined()

  expect(store.user).toBeNull()
  expect(store.ready).toBe(true)
})
