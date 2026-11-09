import { mount } from '@vue/test-utils'
import TimeSlotEditor from '../TimeSlotEditor.vue'
import { i18n } from '../../../i18n'

/*
 * «Кобальт» lays the posting schedule out as a week: one row per weekday,
 * that day's slots as time chips, "+" to add one to that day. Slots are
 * still stored as UTC minutes from the start of the week — the timezone is
 * UTC here so the arithmetic in the assertions stays readable.
 */
const DAY = 1440

function mountEditor(modelValue: number[]) {
  i18n.global.locale.value = 'en'

  return mount(TimeSlotEditor, { props: { modelValue, timezone: 'UTC' } })
}

function day(w: ReturnType<typeof mountEditor>, weekday: number) {
  return w.findAll('[data-test="slot-day"]')[weekday]
}

it('shows the week as seven day rows, each with its own slots in time order', () => {
  const w = mountEditor([DAY + 18 * 60 + 30, DAY + 9 * 60, 3 * DAY + 12 * 60])

  expect(w.findAll('[data-test="slot-day"]')).toHaveLength(7)
  expect(day(w, 1).findAll('[data-test="slot-chip"] input').map((input) => (input.element as HTMLInputElement).value)).toEqual(['09:00', '18:30'])
  expect(day(w, 3).findAll('[data-test="slot-chip"]')).toHaveLength(1)
  expect(day(w, 0).text()).toContain(i18n.global.t('channels.settings.noSlots'))
})

it('adds a 09:00 slot to the day whose "+" was pressed', async () => {
  const w = mountEditor([])

  await day(w, 2).get('[data-test="slot-add"]').trigger('click')

  expect(w.emitted('update:modelValue')?.[0]).toEqual([[2 * DAY + 9 * 60]])
})

it('takes the next free hour when 09:00 is already taken that day', async () => {
  const w = mountEditor([2 * DAY + 9 * 60])

  await day(w, 2).get('[data-test="slot-add"]').trigger('click')

  expect(w.emitted('update:modelValue')?.[0]).toEqual([[2 * DAY + 9 * 60, 2 * DAY + 10 * 60]])
})

it('removes exactly the chip whose × was pressed', async () => {
  const w = mountEditor([DAY + 9 * 60, DAY + 18 * 60])

  await day(w, 1).findAll('[data-test="slot-chip"]')[1].get('button').trigger('click')

  expect(w.emitted('update:modelValue')?.[0]).toEqual([[DAY + 9 * 60]])
})

it('moves a slot when its time is edited in the chip', async () => {
  const w = mountEditor([DAY + 9 * 60])

  await day(w, 1).get('[data-test="slot-chip"] input').setValue('11:15')

  expect(w.emitted('update:modelValue')?.[0]).toEqual([[DAY + 11 * 60 + 15]])
})

it('counts the slots of the whole week', () => {
  expect(mountEditor([DAY, 2 * DAY, 3 * DAY]).get('[data-test="slot-total"]').text()).toContain('3')
})
