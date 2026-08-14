import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import MediaIndex from '../Index.vue';
import { useMediaStore, type MediaItem } from '../../../stores/media';
import { i18n } from '../../../i18n';

const get = vi.fn();
const del = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        delete: (...args: unknown[]) => del(...args),
    },
}));

// AppLayout pulls in the auth/organization stores via useTheme() etc —
// stub it to a plain passthrough so this page test stays about the
// media grid/delete flow, not the whole app chrome.
vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    del.mockReset();
    window.confirm = vi.fn().mockReturnValue(true);
});

function image(id: string): MediaItem {
    return {
        id,
        url: `https://cdn.example.com/${id}.jpg`,
        thumbnail_url: `https://cdn.example.com/${id}-thumb.jpg`,
        mime: 'image/jpeg',
        size_bytes: 100,
        width: 400,
        height: 300,
        original_filename: `${id}.jpg`,
        created_at: '',
    };
}

it('loads the library on mount', async () => {
    get.mockResolvedValueOnce({ data: { data: [image('a')], meta: { current_page: 1, last_page: 1 } } });

    const w = mount(MediaIndex);
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/media', { params: { page: 1, search: undefined } });
    expect(w.findAll('img')).toHaveLength(1);
});

it('deleting shows a specific message on a 409 (media still attached to an unpublished post)', async () => {
    get.mockResolvedValueOnce({ data: { data: [image('a')], meta: { current_page: 1, last_page: 1 } } });
    const conflict = Object.assign(new Error('conflict'), { response: { status: 409 }, isAxiosError: true });
    del.mockRejectedValueOnce(conflict);

    const w = mount(MediaIndex);
    await flushPromises();

    const deleteLabel = i18n.global.t('media.delete');
    await w.get(`button[aria-label="${deleteLabel}"]`).trigger('click');
    await flushPromises();

    expect(w.text()).toContain(i18n.global.t('media.deleteInUse'));

    const store = useMediaStore();
    expect(store.items).toHaveLength(1); // not removed locally — the server refused
});

it('a successful delete removes the tile', async () => {
    get.mockResolvedValueOnce({ data: { data: [image('a')], meta: { current_page: 1, last_page: 1 } } });
    del.mockResolvedValueOnce({});

    const w = mount(MediaIndex);
    await flushPromises();

    const deleteLabel = i18n.global.t('media.delete');
    await w.get(`button[aria-label="${deleteLabel}"]`).trigger('click');
    await flushPromises();

    expect(w.findAll('img')).toHaveLength(0);
});
