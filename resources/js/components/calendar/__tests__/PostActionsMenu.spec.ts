import { mount } from '@vue/test-utils'
import PostActionsMenu from '../PostActionsMenu.vue'

const items = [
  { key: 'share', label: 'Share' },
  { key: 'remove', label: 'Remove', variant: 'danger' as const },
]

function mountMenu(props: Record<string, unknown> = {}) {
  return mount(PostActionsMenu, {
    props: { items, label: 'Post actions', ...props },
    attachTo: document.body,
  })
}

it('starts closed, and says so on the trigger', () => {
  const w = mountMenu()

  expect(w.find('[role="menu"]').exists()).toBe(false)
  expect(w.get('button').attributes('aria-expanded')).toBe('false')
  expect(w.get('button').attributes('aria-haspopup')).toBe('menu')
  expect(w.get('button').attributes('aria-label')).toBe('Post actions')
})

it('opens on a trigger click and renders one menuitem per item', async () => {
  const w = mountMenu()
  await w.get('button').trigger('click')

  expect(w.get('button').attributes('aria-expanded')).toBe('true')
  expect(w.findAll('[role="menuitem"]').map((i) => i.text())).toEqual(['Share', 'Remove'])
})

it('choosing an item emits its key and closes the menu', async () => {
  const w = mountMenu()
  await w.get('button').trigger('click')
  await w.findAll('[role="menuitem"]')[0].trigger('click')

  expect(w.emitted('select')).toEqual([['share']])
  expect(w.find('[role="menu"]').exists()).toBe(false)
})

// The card underneath opens the post editor on click. Without stopping
// propagation, every menu interaction would also open the editor behind
// the menu — see CalendarGrid.vue's eventClick.
it('never lets a menu click reach the card underneath', async () => {
  const onCardClick = vi.fn()
  document.body.addEventListener('click', onCardClick)

  const w = mountMenu()
  await w.get('button').trigger('click')
  await w.findAll('[role="menuitem"]')[0].trigger('click')

  document.body.removeEventListener('click', onCardClick)
  expect(onCardClick).not.toHaveBeenCalled()
})

it('Escape closes the menu', async () => {
  const w = mountMenu()
  await w.get('button').trigger('click')

  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
  await w.vm.$nextTick()

  expect(w.find('[role="menu"]').exists()).toBe(false)
})

it('a click anywhere outside closes the menu', async () => {
  const w = mountMenu()
  await w.get('button').trigger('click')

  document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  await w.vm.$nextTick()

  expect(w.find('[role="menu"]').exists()).toBe(false)
})

it('moves focus into the menu when it opens, so a keyboard user is not left on the trigger', async () => {
  const w = mountMenu()
  await w.get('button').trigger('click')
  await w.vm.$nextTick()

  expect(document.activeElement).toBe(w.findAll('[role="menuitem"]')[0].element)
})

it('renders no trigger at all when there is nothing to do', () => {
  const w = mountMenu({ items: [] })

  expect(w.find('button').exists()).toBe(false)
})
