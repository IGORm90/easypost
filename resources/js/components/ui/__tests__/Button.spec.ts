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
  expect(w.classes()).toEqual(expect.arrayContaining(['border-border-strong', 'bg-surface', 'text-text']))
  expect(w.classes().join(' ')).not.toMatch(/accent|primary/)   // color.md §3.2
})

it('an explicit disabled=false does not re-enable the button while loading — Task 25 review, Minor 1', async () => {
  // Before Button.vue declared its own `disabled` prop, a parent
  // passing `:loading="true" :disabled="false"` (e.g. ScheduleControls'
  // save buttons mid-request, where the caller's own "disabled" reason
  // is unrelated to and can be false during a save) landed via Vue's
  // attrs fallthrough and WON over the component's own
  // `:disabled="loading"` binding, silently re-enabling the button.
  const w = mount(Button, { props: { loading: true, disabled: false } })
  expect((w.element as HTMLButtonElement).disabled).toBe(true)
  await w.trigger('click')
  expect(w.emitted('click')).toBeFalsy()
})

it('disabled alone (not loading) also blocks the click and sets the disabled attribute', async () => {
  const w = mount(Button, { props: { disabled: true } })
  expect((w.element as HTMLButtonElement).disabled).toBe(true)
  await w.trigger('click')
  expect(w.emitted('click')).toBeFalsy()
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

it('gives the primary action the Cobalt weight, so it reads as the one thing to press', () => {
  const w = mount(Button, { props: { variant: 'primary' } })
  expect(w.classes()).toContain('font-semibold')
})

it('draws a row-level destructive action as red text, not a solid red block', () => {
  const w = mount(Button, { props: { variant: 'danger-text' } })
  expect(w.classes()).toContain('text-danger')
  expect(w.classes()).not.toContain('bg-danger')
})
