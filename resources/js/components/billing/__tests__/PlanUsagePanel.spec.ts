import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import PlanUsagePanel from '../PlanUsagePanel.vue';
import { i18n } from '../../../i18n';

const get = vi.fn();

vi.mock('../../../api/client', () => ({
    api: { get: (...args: unknown[]) => get(...args) },
}));

function usage(overrides: Record<string, unknown> = {}) {
    return {
        plan: 'standard',
        usage: {
            channels: { current: 3, max: 10 },
            posts_per_month: { current: 42, max: 500, period: '2026-08' },
            media_storage_mb: { current: 12, max: 1024 },
        },
        ...overrides,
    };
}

async function mountPanel() {
    setActivePinia(createPinia());

    const wrapper = mount(PlanUsagePanel, { global: { plugins: [i18n] } });
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    get.mockReset();
    get.mockResolvedValue({ data: usage() });
});

/*
 * Task 36 specified usage() "for the UI" and nothing ever rendered it, so
 * LimitToast could say "you have hit a limit" while nothing said "you are
 * approaching one". Final review, batch 4 (coverage and gates).
 */
it('shows every limit against its ceiling', async () => {
    const w = await mountPanel();

    expect(get).toHaveBeenCalledWith('/organizations/current/plan');
    expect(w.get('[data-test="usage-channels"]').text()).toBe('3 of 10');
    expect(w.get('[data-test="usage-posts"]').text()).toBe('42 of 500');
    expect(w.get('[data-test="usage-media"]').text()).toBe('12 of 1024');
});

it('names the plan the numbers belong to', async () => {
    const w = await mountPanel();

    expect(w.get('[data-test="plan-name"]').text()).toContain('standard');
});

it('says when the monthly count resets, rather than leaving it implied', async () => {
    const w = await mountPanel();

    expect(w.text()).toContain('2026-08');
});

it('reads a null ceiling as unlimited, not as zero', async () => {
    // The difference matters: "0 of 0" would read as "you may create
    // nothing" on precisely the plan that constrains nothing.
    get.mockResolvedValue({
        data: usage({ usage: { ...usage().usage, channels: { current: 3, max: null } } }),
    });

    const w = await mountPanel();

    expect(w.get('[data-test="usage-channels"]').text()).toBe(i18n.global.t('settings.plan.unlimited'));
});

it('says so plainly when usage cannot be loaded', async () => {
    get.mockRejectedValue(new Error('plan endpoint is down'));

    const w = await mountPanel();

    expect(w.text()).toContain(i18n.global.t('settings.plan.loadError'));
    expect(w.find('[data-test="usage-channels"]').exists()).toBe(false);
});
