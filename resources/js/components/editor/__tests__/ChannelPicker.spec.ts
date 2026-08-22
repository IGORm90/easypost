import { mount } from '@vue/test-utils';
import ChannelPicker from '../ChannelPicker.vue';
import en from '../../../i18n/en.json';
import type { Integration, Provider } from '../../../stores/integrations';
import type { SetDto } from '../types';

/**
 * The picker could apply sets long before it could make one — a set was
 * only creatable through the API by hand (Task 1.4). These specs cover
 * the "save the current selection as a set" half: it must not offer to
 * save an empty selection (POST /api/sets rejects an empty
 * `integration_ids` outright), and the actual request belongs to the
 * parent, because this component is deliberately store-less and
 * HTTP-less — see its docblock.
 */
function integration(id: string, name: string): Integration {
    return {
        id,
        provider_identifier: 'fake',
        name,
        picture: null,
        profile: null,
        settings: null,
        customer_name: null,
        group_name: null,
        disabled: false,
        refresh_needed: false,
        created_at: '2026-08-01T00:00:00Z',
        updated_at: '2026-08-01T00:00:00Z',
    };
}

const PROVIDERS: Provider[] = [];

function mountPicker(modelValue: string[] = [], sets: SetDto[] = []) {
    return mount(ChannelPicker, {
        props: {
            integrations: [integration('i-1', 'First'), integration('i-2', 'Second')],
            providers: PROVIDERS,
            sets,
            modelValue,
        },
    });
}

function buttonWith(wrapper: ReturnType<typeof mountPicker>, label: string) {
    return wrapper.findAll('button').find((button) => button.text() === label);
}

it('does not offer to save a set while no channel is selected', () => {
    const w = mountPicker([]);

    expect(buttonWith(w, en.sets.saveAsSet)).toBeUndefined();
});

it('asks for a name before emitting, rather than saving an unnamed set', async () => {
    const w = mountPicker(['i-1']);

    await buttonWith(w, en.sets.saveAsSet)!.trigger('click');

    expect(w.find('[data-test="save-set-name"] input').exists()).toBe(true);
    expect(w.emitted('save-set')).toBeUndefined();

    // Blank name: the submit button stays disabled rather than letting
    // the parent discover the problem as a 422.
    expect((buttonWith(w, en.sets.save)!.element as HTMLButtonElement).disabled).toBe(true);
});

it('emits the name for the parent to POST, and closes the form again', async () => {
    const w = mountPicker(['i-1', 'i-2']);

    await buttonWith(w, en.sets.saveAsSet)!.trigger('click');
    await w.get('[data-test="save-set-name"] input').setValue('  Launch crew  ');
    await w.get('form[data-test="save-set-form"]').trigger('submit');

    // Trimmed: a name of spaces would pass `required` server-side.
    expect(w.emitted('save-set')).toEqual([['Launch crew']]);
    expect(w.find('[data-test="save-set-name"]').exists()).toBe(false);
});

it('drops the half-typed name when the form is cancelled', async () => {
    const w = mountPicker(['i-1']);

    await buttonWith(w, en.sets.saveAsSet)!.trigger('click');
    await w.get('[data-test="save-set-name"] input').setValue('Draft name');
    await buttonWith(w, en.sets.cancel)!.trigger('click');
    await buttonWith(w, en.sets.saveAsSet)!.trigger('click');

    expect((w.get('[data-test="save-set-name"] input').element as HTMLInputElement).value).toBe('');
    expect(w.emitted('save-set')).toBeUndefined();
});

it('abandons the form when the last selected channel is deselected', async () => {
    const w = mountPicker(['i-1']);

    await buttonWith(w, en.sets.saveAsSet)!.trigger('click');
    await w.setProps({ modelValue: [] });

    expect(w.find('[data-test="save-set-name"]').exists()).toBe(false);
});
