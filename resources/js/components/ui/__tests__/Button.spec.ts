import { mount } from '@vue/test-utils'
import Button from '../Button.vue'

it('emits click when not loading', async () => {
  const w = mount(Button, { props: { loading: false } })
  await w.trigger('click')
  expect(w.emitted('click')).toBeTruthy()
})

it('does not emit click while loading', async () => {
  const w = mount(Button, { props: { loading: true } })
  await w.trigger('click')
  expect(w.emitted('click')).toBeFalsy()
})

it('renders the primary variant on semantic tokens', () => {
  const w = mount(Button, { props: { variant: 'primary' } })
  expect(w.classes()).toContain('bg-primary')
  expect(w.classes()).toContain('text-on-primary')
})

it('renders the secondary variant as an outline, never as accent', () => {
  const w = mount(Button, { props: { variant: 'secondary' } })
  expect(w.classes()).toEqual(expect.arrayContaining(['border-border', 'text-text']))
  expect(w.classes().join(' ')).not.toMatch(/accent|primary/)   // color.md §3.2
})

it('renders the danger variant on the on-danger token, never a raw palette class', () => {
  const w = mount(Button, { props: { variant: 'danger' } })
  expect(w.classes()).toContain('bg-danger')
  expect(w.classes()).toContain('text-on-danger')
  // color.md §1/§2: --on-danger exists precisely because raw white text on
  // dark-theme --danger (#F87171) fails contrast (~2.77:1); text-white must
  // never come back here.
  expect(w.classes()).not.toContain('text-white')
})
