import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChannelLimitMeter from '../ChannelLimitMeter.vue'
import { i18n } from '../../../i18n'

const get = vi.fn()

vi.mock('../../../api/client', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

/*
 * «Кобальт» puts the channel allowance next to the channel list, where the
 * decision to connect one more is actually made — not only on the profile
 * page. It reads the same /organizations/current/plan the profile's panel
 * reads; PlanController only ever reports limits, never enforces them.
 */
function plan(current: number, max: number | null) {
  return { plan: 'standard', usage: { channels: { current, max }, posts_per_month: { current: 0, max: 100 }, media_storage_mb: { current: 0, max: 1024 } } }
}

async function mountMeter(refreshKey = 0) {
  setActivePinia(createPinia())
  i18n.global.locale.value = 'en'
  const w = mount(ChannelLimitMeter, { props: { refreshKey }, global: { plugins: [i18n] } })
  await flushPromises()

  return w
}

beforeEach(() => get.mockReset())

it('shows how many channels are used out of the plan allowance, as a real meter', async () => {
  get.mockResolvedValue({ data: plan(6, 10) })
  const w = await mountMeter()

  const meter = w.get('[role="meter"]')
  expect(meter.attributes('aria-valuenow')).toBe('6')
  expect(meter.attributes('aria-valuemax')).toBe('10')
  expect(w.text()).toContain('6 / 10')
  expect(w.get('[data-test="meter-fill"]').attributes('style')).toContain('width: 60%')
})

it('warns once the allowance is nearly used up', async () => {
  get.mockResolvedValue({ data: plan(9, 10) })
  expect((await mountMeter()).get('[data-test="meter-fill"]').classes()).toContain('bg-warning')

  get.mockResolvedValue({ data: plan(5, 10) })
  expect((await mountMeter()).get('[data-test="meter-fill"]').classes()).toContain('bg-primary')
})

it('says "unlimited" instead of drawing a bar against no limit', async () => {
  get.mockResolvedValue({ data: plan(6, null) })
  const w = await mountMeter()

  expect(w.find('[role="meter"]').exists()).toBe(false)
  expect(w.text()).toContain(i18n.global.t('settings.plan.unlimited'))
})

it('stays out of the way when the plan cannot be loaded', async () => {
  get.mockImplementation((url?: string) => (url === '/organizations/current/plan' ? Promise.reject(new Error('down')) : Promise.resolve({ data: null })))

  expect((await mountMeter()).find('[data-test="channel-limit"]').exists()).toBe(false)
})

it('reloads when the page says the channel count changed', async () => {
  get.mockResolvedValue({ data: plan(6, 10) })
  const w = await mountMeter()

  get.mockResolvedValue({ data: plan(7, 10) })
  await w.setProps({ refreshKey: 1 })
  await flushPromises()

  expect(w.text()).toContain('7 / 10')
})
