import { flushPromises, mount } from '@vue/test-utils'
import TagManager from '../TagManager.vue'
import en from '../../../i18n/en.json'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()
const confirm = vi.fn()

vi.mock('../../../api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    delete: (...args: unknown[]) => del(...args),
  },
}))

function tag(overrides: Record<string, unknown> = {}) {
  return { id: 'tag-1', name: 'Launch', color: '#0F726B', post_groups_count: 3, ...overrides }
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  del.mockReset()
  confirm.mockReset()
  // happy-dom ships no window.confirm at all, so it has to be defined
  // rather than spied on.
  Object.defineProperty(window, 'confirm', { value: confirm, configurable: true, writable: true })
  get.mockResolvedValue({ data: [tag()] })
})

async function mountManager() {
  const w = mount(TagManager, { props: { open: true }, global: { stubs: { teleport: true } } })
  await flushPromises()

  return w
}

function buttonWith(w: ReturnType<typeof mount>, label: string) {
  return w.findAll('button').find((b) => b.text() === label)
}

it('loads the tags when it opens and shows each name with its usage count', async () => {
  const w = await mountManager()

  expect(get).toHaveBeenCalledWith('/tags')
  expect(w.text()).toContain('Launch')
  expect(w.text()).toContain(en.tags.usage.replace('{count}', '3'))
})

it('shows an empty state rather than a bare list when there are no tags', async () => {
  get.mockResolvedValue({ data: [] })

  const w = await mountManager()

  expect(w.text()).toContain(en.tags.empty)
})

it('creates a tag and reports the created one, so the caller can select it', async () => {
  const created = tag({ id: 'tag-new', name: 'Promo', post_groups_count: 0 })
  post.mockResolvedValueOnce({ data: created })

  const w = await mountManager()
  await w.get('[data-test="tag-create-name"] input').setValue('Promo')
  // The create button is the form's submit, so this is also the path a
  // keyboard user takes by pressing Enter in the name field.
  await w.get('form').trigger('submit')
  await flushPromises()

  expect(post).toHaveBeenCalledWith('/tags', { name: 'Promo', color: expect.any(String) })
  expect(w.emitted('created')).toEqual([[created]])
  expect(w.emitted('changed')).toBeTruthy()
})

// PUT is a full replace on this endpoint — both fields are `required`
// server-side — so a rename that omitted the colour would 422.
it('renames a tag by sending both the name and the colour', async () => {
  put.mockResolvedValueOnce({ data: tag({ name: 'Launched' }) })

  const w = await mountManager()
  await buttonWith(w, en.tags.rename)?.trigger('click')
  await w.get('[data-test="tag-edit-name"] input').setValue('Launched')
  await buttonWith(w, en.tags.save)?.trigger('click')
  await flushPromises()

  expect(put).toHaveBeenCalledWith('/tags/tag-1', { name: 'Launched', color: '#0F726B' })
  expect(w.emitted('changed')).toBeTruthy()
})

// The whole point of the count coming back from the server: the warning
// has to name how many posts lose the tag, not guess.
it('names the number of affected posts in the delete confirmation', async () => {
  confirm.mockReturnValue(false)

  const w = await mountManager()
  await buttonWith(w, en.tags.remove)?.trigger('click')

  expect(confirm).toHaveBeenCalledWith(en.tags.removeConfirm.replace('{name}', 'Launch').replace('{count}', '3'))
  expect(del).not.toHaveBeenCalled()
})

it('says plainly that nothing uses the tag rather than confirming against zero posts', async () => {
  get.mockResolvedValue({ data: [tag({ post_groups_count: 0 })] })
  confirm.mockReturnValue(false)

  const w = await mountManager()
  await buttonWith(w, en.tags.remove)?.trigger('click')

  expect(confirm).toHaveBeenCalledWith(en.tags.removeConfirmUnused.replace('{name}', 'Launch'))
})

it('deletes only after the confirmation is accepted', async () => {
  confirm.mockReturnValue(true)
  del.mockResolvedValueOnce({})

  const w = await mountManager()
  await buttonWith(w, en.tags.remove)?.trigger('click')
  await flushPromises()

  expect(del).toHaveBeenCalledWith('/tags/tag-1')
  expect(w.emitted('changed')).toBeTruthy()
})

// A duplicate name is the one error the user will actually hit, and it
// belongs on the field, not in a generic toast they have to map back.
it('puts a 422 field error next to the field that caused it', async () => {
  post.mockRejectedValueOnce({
    isAxiosError: true,
    response: { status: 422, data: { errors: { name: ['A tag with this name already exists.'] } } },
  })

  const w = await mountManager()
  await w.get('[data-test="tag-create-name"] input').setValue('launch')
  // The create button is the form's submit, so this is also the path a
  // keyboard user takes by pressing Enter in the name field.
  await w.get('form').trigger('submit')
  await flushPromises()

  expect(w.get('[data-test="tag-create-name"]').text()).toContain('A tag with this name already exists.')
})
