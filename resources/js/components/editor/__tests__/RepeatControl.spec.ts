import { mount } from '@vue/test-utils';
import RepeatControl from '../RepeatControl.vue';
import { i18n } from '../../../i18n';
import type { RepeatRule } from '../types';

/**
 * Block 4. The control owns the recurrence rule and nothing else: it
 * never talks to the API, and it converts `until` through the timezone
 * it is handed rather than the one the browser happens to be in — the
 * invariant zonedDateTime.ts documents and every other date field in
 * this editor already follows.
 */
const NEVER: RepeatRule = { unit: null, interval: 1, until: null };

function control(modelValue: RepeatRule = NEVER, timezone = 'America/Chicago') {
    return mount(RepeatControl, {
        props: { modelValue, timezone },
        global: { plugins: [i18n] },
    });
}

it('shows only the unit chooser until a unit is picked', () => {
    // A repeat interval and an end date are meaningless without a unit,
    // so they are absent rather than disabled.
    const wrapper = control();

    expect(wrapper.find('[data-testid="repeat-unit"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="repeat-interval"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="repeat-until"]').exists()).toBe(false);
});

it('reveals the interval and end date once a unit is chosen', async () => {
    const wrapper = control({ unit: 'WEEK', interval: 2, until: '2026-09-01T14:00:00Z' });

    expect(wrapper.find('[data-testid="repeat-interval"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="repeat-until"]').exists()).toBe(true);
});

it('emits a whole rule when a unit is picked', async () => {
    const wrapper = control();

    await wrapper.find('[data-testid="repeat-unit"]').setValue('DAY');

    expect(wrapper.emitted('update:modelValue')?.[0][0]).toEqual({ unit: 'DAY', interval: 1, until: null });
});

it('clears the interval and the end date together when the repeat is switched off', async () => {
    // Half a rule left behind is a rule a later edit can resurrect
    // without the user ever seeing it.
    const wrapper = control({ unit: 'WEEK', interval: 3, until: '2026-09-01T14:00:00Z' });

    await wrapper.find('[data-testid="repeat-unit"]').setValue('');

    expect(wrapper.emitted('update:modelValue')?.[0][0]).toEqual({ unit: null, interval: 1, until: null });
});

it('reads the end date in the timezone it was given, not the browser one', () => {
    // The suite runs with TZ=America/Chicago on purpose; passing a
    // different zone here is what proves the prop is actually used.
    const wrapper = control({ unit: 'WEEK', interval: 1, until: '2026-07-01T12:00:00Z' }, 'Europe/Berlin');

    expect((wrapper.find('[data-testid="repeat-until"]').element as HTMLInputElement).value).toBe('2026-07-01T14:00');
});

it('emits the end date back as UTC', async () => {
    const wrapper = control({ unit: 'WEEK', interval: 1, until: null }, 'Europe/Berlin');

    await wrapper.find('[data-testid="repeat-until"]').setValue('2026-07-01T14:00');

    expect(wrapper.emitted('update:modelValue')?.[0][0]).toMatchObject({ until: '2026-07-01T12:00:00.000Z' });
});

it('never emits an interval below one', async () => {
    // The backend caps the interval per unit and rejects zero; the field
    // should not be able to produce a payload that exists only to be
    // 422'd.
    const wrapper = control({ unit: 'DAY', interval: 2, until: null });

    await wrapper.find('[data-testid="repeat-interval"]').setValue('0');

    expect(wrapper.emitted('update:modelValue')?.[0][0]).toMatchObject({ interval: 1 });
});

it('caps the interval at what its unit can mean', async () => {
    const wrapper = control({ unit: 'MONTH', interval: 2, until: null });

    await wrapper.find('[data-testid="repeat-interval"]').setValue('13');

    expect(wrapper.emitted('update:modelValue')?.[0][0]).toMatchObject({ interval: 12 });
});

it('labels every field for a screen reader', () => {
    // a11y.spec.ts only guards `<Input>` call sites; these are raw
    // elements, so the labels are asserted here instead.
    const wrapper = control({ unit: 'DAY', interval: 1, until: null });

    for (const field of ['repeat-unit', 'repeat-interval', 'repeat-until']) {
        const id = wrapper.find(`[data-testid="${field}"]`).attributes('id');
        expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true);
    }
});
