import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import ConnectProviderModal from '../ConnectProviderModal.vue';
import type { Provider } from '../../../stores/integrations';

const post = vi.fn();

vi.mock('../../../api/client', () => ({
    api: { post: (...args: unknown[]) => post(...args) },
}));

beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
});

const telegram: Provider = {
    identifier: 'telegram',
    name: 'Telegram',
    auth_type: 'manual',
    capabilities: {
        maxCharacters: 1000,
        maxImages: 10,
        maxVideos: 1,
        supportsThreads: false,
        supportsScheduling: true,
        allowedMimeTypes: [],
        maxFileSizeBytes: 0,
        allowsMixedMedia: true,
        maxThreadLength: null,
        requiresAltText: false,
    },
};

// Task 36 fix round 1, Minor 5: a 402 on `POST .../auth-url` already
// shows the global warning toast (api/client.ts's response interceptor,
// color.md --warning) — this component's own local `text-danger` line
// must not ALSO fire for that same event (color.md §3.8: never put
// accent/warning red and a real --danger line on screen together for one
// failure). Every other failure (403, network) keeps its local line.
// Modal.vue teleports its content to <body>, outside the mounted
// wrapper's own root element — same fix MediaPicker.spec.ts/
// ThreadEditor.spec.ts already use for the same reason.
it('shows no local error line for a 402 — the global limit toast already covers it', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 402, data: { message: 'limit reached' } } });

    mount(ConnectProviderModal, {
        props: { open: true, providers: [telegram], preselect: 'telegram' },
        attachTo: document.body,
    });
    await flushPromises();

    const form = document.body.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(1);
    expect(document.body.querySelector('.text-danger')).toBeNull();
});

it('still shows a local error line for a non-402 failure (e.g. a permission error)', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 403, data: {} } });

    mount(ConnectProviderModal, {
        props: { open: true, providers: [telegram], preselect: 'telegram' },
        attachTo: document.body,
    });
    await flushPromises();

    const form = document.body.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    await flushPromises();

    expect(document.body.querySelector('.text-danger')).not.toBeNull();
});
