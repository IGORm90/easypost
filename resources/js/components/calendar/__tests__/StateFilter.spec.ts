import { mount } from '@vue/test-utils';
import StateFilter from '../StateFilter.vue';
import DisplayModeSwitcher from '../DisplayModeSwitcher.vue';
import { i18n } from '../../../i18n';

/**
 * Both controls Block 2 adds to the calendar toolbar are segmented
 * controls that report a state, so both fall under the rule
 * `primaryBudget.spec.ts` already enforces for ViewSwitcher and
 * TagFilter (color.md §3.1: the active nav link and "New post" are this
 * screen's two primaries, and that is the limit). The `restingPrimary()`
 * helper is repeated here rather than exported from that spec — it is
 * three lines, and a shared test helper that two specs import is a
 * dependency between them.
 */
function restingPrimary(classes: string[]): string[] {
    return classes.filter((name) => /^(?:bg|text|border)-primary/.test(name));
}

beforeEach(() => {
    i18n.global.locale.value = 'en';
});

it('marks the selected state with aria-pressed and no primary tint', () => {
    const w = mount(StateFilter, { props: { modelValue: 'ERROR' }, global: { plugins: [i18n] } });

    const active = w.findAll('button').find((button) => button.attributes('aria-pressed') === 'true');

    expect(active).toBeDefined();
    expect(active?.text()).toBe('Failed');
    expect(restingPrimary(active?.classes() ?? [])).toEqual([]);
    expect(active?.classes().join(' ')).toMatch(/font-semibold/);
});

it('offers every post state plus "all", and emits the one clicked', async () => {
    const w = mount(StateFilter, { props: { modelValue: 'all' }, global: { plugins: [i18n] } });

    const labels = w.findAll('button').map((button) => button.text());
    expect(labels).toEqual(['All', 'Drafts', 'Scheduled', 'Published', 'Failed']);

    await w.findAll('button')[1].trigger('click');
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['DRAFT']);
});

it('marks the active display mode without spending the primary budget', async () => {
    const w = mount(DisplayModeSwitcher, { props: { modelValue: 'grid' }, global: { plugins: [i18n] } });

    const active = w.findAll('button').find((button) => button.attributes('aria-pressed') === 'true');

    expect(active?.text()).toBe('Grid');
    expect(restingPrimary(active?.classes() ?? [])).toEqual([]);

    await w.findAll('button')[1].trigger('click');
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['list']);
});
