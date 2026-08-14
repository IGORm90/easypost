import { mount } from '@vue/test-utils';
import ViewSwitcher from '../ViewSwitcher.vue';
import TagFilter from '../TagFilter.vue';
import { i18n } from '../../../i18n';

/**
 * `focus-visible:ring-primary` is explicitly what color.md gives primary
 * to (the focus ring), and is not what §3.1 counts — only a resting
 * background/text/border tint is.
 */
function restingPrimary(classes: string[]): string[] {
    return classes.filter((name) => /^(?:bg|text|border)-primary/.test(name));
}

/*
 * Final review, Important 9 (color.md §3.1: "one primary element per
 * screen; the main button and the active nav item are already two, and
 * that is the limit").
 *
 * The calendar had four before the editor was even open: the active nav
 * link, the "New post" button, this view switcher's active segment and
 * every selected tag chip. The two that carry no action — a segmented
 * control and a filter chip both merely report a state — give theirs up.
 * Neither loses its non-colour signal: the switcher keeps `aria-pressed`
 * plus a weight change, the chip keeps `aria-pressed` plus a check icon
 * (color.md §4: never colour alone).
 */

beforeEach(() => {
    i18n.global.locale.value = 'en';
});

it('marks the active view without spending the screen\'s primary budget', () => {
    const w = mount(ViewSwitcher, { props: { modelValue: 'week' }, global: { plugins: [i18n] } });

    const active = w.findAll('button').find((button) => button.attributes('aria-pressed') === 'true');

    expect(active).toBeDefined();
    expect(restingPrimary(active?.classes() ?? [])).toEqual([]);
    // Still unmistakably the active one, by weight and surface.
    expect(active?.classes().join(' ')).toMatch(/font-semibold/);
});

it('marks a selected tag without spending the screen\'s primary budget', () => {
    const w = mount(TagFilter, {
        props: { modelValue: ['tag-1'], tags: [{ id: 'tag-1', name: 'Launch', color: '#0F726B' }] },
        global: { plugins: [i18n] },
    });

    const chip = w.get('button[aria-pressed="true"]');

    expect(restingPrimary(chip.classes())).toEqual([]);
    // The check icon is the structural signal and must survive.
    expect(chip.find('svg').exists()).toBe(true);
    expect(restingPrimary(chip.get('svg').classes())).toEqual([]);
});
