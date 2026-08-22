import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ShareLinkModal from '../ShareLinkModal.vue'
import en from '../../../i18n/en.json'

const share = vi.fn()
const unshare = vi.fn()

vi.mock('../../../stores/posts', () => ({
  usePostsStore: () => ({ share, unshare }),
}))

const writeText = vi.fn()
// happy-dom ships no window.confirm at all, so it is installed rather
// than spied on. The repo uses window.confirm for every destructive
// confirmation (see CalendarGrid.vue, Settings/Team.vue).
const confirm = vi.fn()

beforeEach(() => {
  setActivePinia(createPinia())
  share.mockReset()
  unshare.mockReset()
  writeText.mockReset().mockResolvedValue(undefined)
  // happy-dom exposes `navigator.clipboard` as a getter-only property, so
  // it has to be redefined rather than assigned.
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
  confirm.mockReset()
  Object.defineProperty(window, 'confirm', { value: confirm, configurable: true, writable: true })
})

function mountModal(props: Record<string, unknown> = {}) {
  return mount(ShareLinkModal, {
    props: { open: true, groupId: 'group-1', shareToken: null, ...props },
    global: { stubs: { teleport: true } },
  })
}

function buttonWith(w: ReturnType<typeof mountModal>, label: string) {
  return w.findAll('button').find((b) => b.text() === label)
}

it('offers to create a link when the post has none, and says so in words', () => {
  const w = mountModal()

  expect(w.text()).toContain(en.calendar.share.inactive)
  expect(buttonWith(w, en.calendar.share.create)).toBeTruthy()
  expect(w.find('input').exists()).toBe(false)
})

it('creating a link calls the store and then shows the returned url', async () => {
  share.mockResolvedValue('https://app.example/share/tok-1')
  const w = mountModal()

  await buttonWith(w, en.calendar.share.create)?.trigger('click')
  await new Promise((resolve) => setTimeout(resolve))

  expect(share).toHaveBeenCalledWith('group-1')
  expect(w.get('input').element.value).toBe('https://app.example/share/tok-1')
  expect(w.emitted('changed')).toEqual([['tok-1']])
})

it('shows an existing link without calling the API', () => {
  const w = mountModal({ shareToken: 'tok-9' })

  expect(share).not.toHaveBeenCalled()
  expect(w.get('input').element.value).toContain('/share/tok-9')
  expect(w.text()).toContain(en.calendar.share.active)
})

// a11y.spec.ts: every Input needs a real label. The URL field is
// read-only, which makes it no less of a form control.
it('labels the url field and keeps it read-only', () => {
  const w = mountModal({ shareToken: 'tok-9' })
  const input = w.get('input')

  expect(input.attributes('readonly')).toBeDefined()
  expect(w.text()).toContain(en.calendar.share.linkLabel)
})

it('copying writes the url to the clipboard and confirms it', async () => {
  const w = mountModal({ shareToken: 'tok-9' })

  await buttonWith(w, en.ui.copy)?.trigger('click')
  await new Promise((resolve) => setTimeout(resolve))

  expect(writeText).toHaveBeenCalledWith(w.get('input').element.value)
  expect(buttonWith(w, en.ui.copied)).toBeTruthy()
})

it('revoking asks for confirmation first', async () => {
  confirm.mockReturnValue(false)
  const w = mountModal({ shareToken: 'tok-9' })

  await buttonWith(w, en.calendar.share.revoke)?.trigger('click')

  expect(confirm).toHaveBeenCalledWith(en.calendar.share.revokeConfirm)
  expect(unshare).not.toHaveBeenCalled()
})

it('a confirmed revoke clears the link and reports it upward', async () => {
  confirm.mockReturnValue(true)
  unshare.mockResolvedValue(undefined)
  const w = mountModal({ shareToken: 'tok-9' })

  await buttonWith(w, en.calendar.share.revoke)?.trigger('click')
  await new Promise((resolve) => setTimeout(resolve))

  expect(unshare).toHaveBeenCalledWith('group-1')
  expect(w.emitted('changed')).toEqual([[null]])
  expect(w.find('input').exists()).toBe(false)
})

it('a failed create surfaces an error instead of throwing', async () => {
  share.mockRejectedValue({ isAxiosError: true, response: { status: 500 } })
  const w = mountModal()

  await buttonWith(w, en.calendar.share.create)?.trigger('click')
  await new Promise((resolve) => setTimeout(resolve))

  expect(w.text()).toContain(en.calendar.share.createError)
})

it('a failed revoke surfaces an error and leaves the link visible', async () => {
  confirm.mockReturnValue(true)
  unshare.mockRejectedValue({ isAxiosError: true, response: { status: 500 } })
  const w = mountModal({ shareToken: 'tok-9' })

  await buttonWith(w, en.calendar.share.revoke)?.trigger('click')
  await new Promise((resolve) => setTimeout(resolve))

  expect(w.text()).toContain(en.calendar.share.revokeError)
  expect(w.find('input').exists()).toBe(true)
})

// Reopening on a different post must not show the previous post's link.
it('re-reads the token when it is reopened for another group', async () => {
  const w = mountModal({ open: false, shareToken: null })

  await w.setProps({ groupId: 'group-2', shareToken: 'tok-2', open: true })

  expect(w.get('input').element.value).toContain('/share/tok-2')
})
