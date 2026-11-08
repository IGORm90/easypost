import { mount } from '@vue/test-utils'
import Badge from '../Badge.vue'

/**
 * color.md §1: every status reads as text on its soft fill, framed by its
 * own -border token — the frame is what keeps a pale "Published" badge
 * from dissolving into a white card.
 */
it.each([
  ['success', ['bg-success-soft', 'text-success', 'border-success-border']],
  ['warning', ['bg-warning-soft', 'text-warning', 'border-warning-border']],
  ['danger', ['bg-danger-soft', 'text-danger', 'border-danger-border']],
  ['neutral', ['bg-accent-soft', 'text-accent']],
  ['scheduled', ['bg-primary-soft', 'text-primary-hover']],
] as const)('draws the %s status on its own tokens', (status, classes) => {
  const w = mount(Badge, { props: { status }, slots: { default: 'x' } })
  expect(w.classes()).toEqual(expect.arrayContaining([...classes]))
})
