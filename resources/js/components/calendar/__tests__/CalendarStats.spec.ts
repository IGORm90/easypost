import { mount } from '@vue/test-utils'
import CalendarStats from '../CalendarStats.vue'
import { i18n } from '../../../i18n'

/*
 * The four counters above the calendar. They count PostGroups in the range
 * the calendar is showing — the same entries the grid or lanes draw — so
 * they can never disagree with what is on screen.
 */
function mountStats(states: string[]) {
  i18n.global.locale.value = 'en'

  return mount(CalendarStats, {
    props: { entries: states.map((state, index) => ({ id: `g${index}`, state })) },
    global: { plugins: [i18n] },
  })
}

function value(w: ReturnType<typeof mountStats>, key: string): string {
  return w.get(`[data-test="stat-${key}"] [data-test="stat-value"]`).text()
}

it('counts scheduled (including in-flight), published, drafts and failures', () => {
  const w = mountStats(['SCHEDULED', 'QUEUE', 'SCHEDULED', 'PUBLISHED', 'DRAFT', 'DRAFT', 'ERROR'])

  expect(value(w, 'scheduled')).toBe('3')
  expect(value(w, 'published')).toBe('1')
  expect(value(w, 'drafts')).toBe('2')
  expect(value(w, 'errors')).toBe('1')
})

it('turns the failure counter red only when something actually failed', () => {
  expect(mountStats(['ERROR']).get('[data-test="stat-errors"]').classes()).toContain('bg-danger-soft')
  expect(mountStats(['PUBLISHED']).get('[data-test="stat-errors"]').classes()).not.toContain('bg-danger-soft')
})

it('labels every counter in words, so a number never stands alone', () => {
  const w = mountStats([])

  for (const key of ['scheduled', 'published', 'drafts', 'errors']) {
    expect(w.get(`[data-test="stat-${key}"]`).text()).toContain(i18n.global.t(`calendar.stats.${key}`))
  }
})

it('offers a way from the failure counter to the failed posts, but only when there are some', async () => {
  const none = mountStats(['PUBLISHED'])
  expect(none.find('[data-test="stat-errors"] button').exists()).toBe(false)

  const some = mountStats(['ERROR'])
  await some.get('[data-test="stat-errors"] button').trigger('click')
  expect(some.emitted('show-errors')).toHaveLength(1)
})
