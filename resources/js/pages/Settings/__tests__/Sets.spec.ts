import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Sets from '../Sets.vue';
import en from '../../../i18n/en.json';
import type { Integration } from '../../../stores/integrations';
import type { SetDto } from '../../../components/editor/types';

/**
 * Sets were readable from the SPA long before they were manageable in it
 * — the editor listed them and applied them, but the only way to create
 * one was the API by hand. These specs pin the parts of the contract this
 * screen has to respect: PUT is a full replace (a rename must carry the
 * channels too, or the set comes back 422), an empty `integration_ids`
 * is rejected outright, and SetResource re-resolves the stored ids on
 * read, so a set whose channels were all disconnected arrives empty and
 * cannot be saved again until one is added back.
 */
const get = vi.fn();
const post = vi.fn();
const put = vi.fn();
const del = vi.fn();
const confirm = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
        put: (...args: unknown[]) => put(...args),
        delete: (...args: unknown[]) => del(...args),
    },
}));

// The page under test is the list and its forms, not the app chrome —
// AppLayout drags in the auth/organization stores and the theme.
vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

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

const CHANNELS = [integration('i-1', 'Newsroom'), integration('i-2', 'Announcements')];

function set(overrides: Partial<SetDto> = {}): SetDto {
    return { id: 'set-1', name: 'Launch crew', integration_ids: ['i-1'], ...overrides };
}

function respondWith(sets: SetDto[], channels: Integration[] = CHANNELS): void {
    get.mockImplementation((url: string) => {
        if (url === '/sets') {
            return Promise.resolve({ data: sets });
        }

        if (url === '/integrations') {
            return Promise.resolve({ data: channels });
        }

        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    put.mockReset();
    del.mockReset();
    confirm.mockReset();
    // happy-dom ships no window.confirm at all, so it has to be defined
    // rather than spied on.
    Object.defineProperty(window, 'confirm', { value: confirm, configurable: true, writable: true });
    confirm.mockReturnValue(true);
    respondWith([set()]);
});

async function mountPage() {
    const w = mount(Sets);
    await flushPromises();

    return w;
}

function buttonWith(wrapper: Awaited<ReturnType<typeof mountPage>>, label: string) {
    return wrapper.findAll('button').find((button) => button.text() === label);
}

it('names the channels a set contains rather than showing their ids', async () => {
    const w = await mountPage();

    expect(get).toHaveBeenCalledWith('/sets');
    expect(w.text()).toContain('Launch crew');
    expect(w.text()).toContain('Newsroom');
    expect(w.text()).not.toContain('i-1');
});

it('explains what a set is for instead of showing an empty list', async () => {
    respondWith([]);
    const w = await mountPage();

    expect(w.text()).toContain(en.sets.empty);
    expect(w.text()).toContain(en.sets.emptyHint);
});

it('creates a set from the ticked channels', async () => {
    respondWith([]);
    post.mockResolvedValue({ data: set({ id: 'set-2', name: 'Everything', integration_ids: ['i-1', 'i-2'] }) });
    const w = await mountPage();

    await w.get('[data-test="set-create-name"] input').setValue('Everything');
    await w.get('[data-test="set-create-channels"] input[value="i-1"]').setValue(true);
    await w.get('[data-test="set-create-channels"] input[value="i-2"]').setValue(true);
    await w.get('form[data-test="set-create"]').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/sets', { name: 'Everything', integration_ids: ['i-1', 'i-2'] });
    expect(w.text()).toContain('Everything');
});

it('refuses to submit a set with no channel, rather than collecting a 422 for it', async () => {
    respondWith([]);
    const w = await mountPage();

    await w.get('[data-test="set-create-name"] input').setValue('Nobody');

    expect((buttonWith(w, en.sets.create)!.element as HTMLButtonElement).disabled).toBe(true);
    expect(post).not.toHaveBeenCalled();
});

it('sends the channels along with a rename, because PUT is a full replace', async () => {
    put.mockResolvedValue({ data: set({ name: 'Launch team' }) });
    const w = await mountPage();

    await buttonWith(w, en.sets.rename)!.trigger('click');
    await w.get('[data-test="set-edit-name"] input').setValue('Launch team');
    await buttonWith(w, en.sets.save)!.trigger('click');
    await flushPromises();

    expect(put).toHaveBeenCalledWith('/sets/set-1', { name: 'Launch team', integration_ids: ['i-1'] });
    expect(w.text()).toContain('Launch team');
});

it('flags a set whose channels have all been disconnected', async () => {
    respondWith([set({ integration_ids: [] })]);
    const w = await mountPage();

    expect(w.text()).toContain(en.sets.orphaned);

    // And it cannot be saved back as-is: the API rejects an empty list.
    await buttonWith(w, en.sets.rename)!.trigger('click');
    expect((buttonWith(w, en.sets.save)!.element as HTMLButtonElement).disabled).toBe(true);
});

it('deletes only after the confirmation is accepted', async () => {
    confirm.mockReturnValue(false);
    const w = await mountPage();

    await buttonWith(w, en.sets.remove)!.trigger('click');
    await flushPromises();

    expect(confirm).toHaveBeenCalledWith(en.sets.removeConfirm.replace('{name}', 'Launch crew'));
    expect(del).not.toHaveBeenCalled();
    expect(w.text()).toContain('Launch crew');
});

it('drops the row once the delete goes through', async () => {
    del.mockResolvedValue({});
    const w = await mountPage();

    await buttonWith(w, en.sets.remove)!.trigger('click');
    await flushPromises();

    expect(del).toHaveBeenCalledWith('/sets/set-1');
    expect(w.text()).not.toContain('Launch crew');
});

it('puts a 422 message next to the field that caused it', async () => {
    respondWith([]);
    post.mockRejectedValue(
        Object.assign(new Error('unprocessable'), {
            isAxiosError: true,
            response: { status: 422, data: { errors: { name: ['A set called that already exists.'] } } },
        }),
    );
    const w = await mountPage();

    await w.get('[data-test="set-create-name"] input').setValue('Everything');
    await w.get('[data-test="set-create-channels"] input[value="i-1"]').setValue(true);
    await w.get('form[data-test="set-create"]').trigger('submit');
    await flushPromises();

    expect(w.get('[data-test="set-create-name"]').text()).toContain('A set called that already exists.');
});

it('says to connect a channel first when the organization has none', async () => {
    respondWith([], []);
    const w = await mountPage();

    expect(w.text()).toContain(en.sets.noChannels);
    expect(w.find('form[data-test="set-create"]').exists()).toBe(false);
});
