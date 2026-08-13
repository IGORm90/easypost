import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createI18n } from 'vue-i18n'
import en from '../../../i18n/en.json'

const get = vi.fn()

vi.mock('../../../api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
  },
}))

async function mountAt(token: string) {
  const { default: SharedPost } = await import('../SharedPost.vue')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/share/:token', name: 'shared-post', component: SharedPost }],
  })
  router.push(`/share/${token}`)
  await router.isReady()

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })

  return mount(SharedPost, { global: { plugins: [router, i18n] } })
}

beforeEach(() => {
  get.mockClear()
})

it('requests the token from the route and renders the public payload', async () => {
  get.mockResolvedValueOnce({
    data: {
      publish_date: '2026-09-01T10:00:00.000000Z',
      channels: [{ provider_identifier: 'telegram', name: 'Announcements', picture: null, content: ['Hello there'] }],
    },
  })

  const w = await mountAt('a-token')
  await flushPromises()

  expect(get).toHaveBeenCalledWith('/public/posts/a-token')
  expect(w.text()).toContain('Hello there')
  expect(w.text()).toContain('Announcements')
})

it('shows a not-found state for a 404 (revoked or unknown token alike), never a raw error', async () => {
  get.mockRejectedValueOnce({ isAxiosError: true, response: { status: 404 } })

  const w = await mountAt('missing')
  await flushPromises()

  expect(w.text()).toContain(en.sharedPost.notFound)
})

it('never renders post content as markup: a script-like payload stays inert text, not v-html', async () => {
  const malicious = '<img src=x onerror=alert(1)>'
  get.mockResolvedValueOnce({
    data: {
      publish_date: null,
      channels: [{ provider_identifier: 'telegram', name: 'Announcements', picture: null, content: [malicious] }],
    },
  })

  const w = await mountAt('a-token')
  await flushPromises()

  // Vue's default text interpolation escapes this into literal text —
  // it must never become a second, real <img> element parsed out of the
  // post content. The only <img> this page can ever render is the
  // channel picture (an attribute binding, never markup from `content`),
  // and this fixture has none.
  expect(w.html()).toContain('&lt;img src=x onerror=alert(1)&gt;')
  expect(w.findAll('img')).toHaveLength(0)
})

async function flushPromises(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}
