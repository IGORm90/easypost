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
    localStorage.clear();
});

// Modal.vue teleports to <body>, outside wherever the wrapper itself was
// mounted — several specs below use `attachTo: document.body` for that
// reason, and without cleanup each one's teleported form would still be
// sitting in the DOM for the next test's own `document.body.querySelector`.
afterEach(() => {
    document.body.innerHTML = '';
});

const telegram: Provider = {
    identifier: 'telegram',
    name: 'Telegram',
    auth_type: 'manual',
    auth_fields: [
        { key: 'bot_token', type: 'password' },
        { key: 'chat_id', type: 'text' },
    ],
    settings_fields: [],
    post_settings: [],
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
        supportedMarks: [],
        requiresMedia: false,
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
        props: { open: true, provider: telegram },
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
        props: { open: true, provider: telegram },
        attachTo: document.body,
    });
    await flushPromises();

    const form = document.body.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    await flushPromises();

    expect(document.body.querySelector('.text-danger')).not.toBeNull();
});

// Final review, Important 10: the credential form's shape is the
// provider's own declaration (GET /api/providers -> auth_fields), not a
// map of provider names this component keeps. Renders whatever it is
// told, in the declared order, with the declared input types.
//
// Task 37: the picker step this used to require reaching through first
// is gone — the card that triggers this modal already committed to a
// provider (Channels/Index.vue's `beginConnect()`), so the modal opens
// straight on the credential form.
it('renders exactly the fields the provider declares, in order, with no picker step', async () => {
    const w = mount(ConnectProviderModal, {
        props: { open: true, provider: telegram },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    const inputs = w.findAll('input');
    expect(inputs.map((input) => input.attributes('id'))).toEqual(['connect-bot_token', 'connect-chat_id']);
    expect(inputs.map((input) => input.attributes('type'))).toEqual(['password', 'text']);
    expect(w.findAll('button').map((b) => b.text())).not.toContain('Telegram');
});

it('falls back to a single generic credential field for a manual provider that declares none', async () => {
    const w = mount(ConnectProviderModal, {
        props: { open: true, provider: { ...telegram, auth_fields: [] } },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.findAll('input').map((input) => input.attributes('id'))).toEqual(['connect-token']);
});

it('renders nothing when it has no provider yet (closed, or between props updates)', async () => {
    const w = mount(ConnectProviderModal, {
        props: { open: false, provider: null },
        global: { stubs: { teleport: true } },
    });
    await flushPromises();

    expect(w.findAll('input')).toHaveLength(0);
});

it('asks for the declared fields before redirecting an oauth provider that needs one', async () => {
    const oauthWithField: Provider = { ...telegram, auth_type: 'oauth', auth_fields: [{ key: 'instance', type: 'text' }] };
    const tab = { location: { href: '' }, close: vi.fn() };
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window);
    post.mockResolvedValueOnce({ data: { url: 'https://oauth.example/authorize', state: 's', extra: {} } });

    mount(ConnectProviderModal, {
        props: { open: true, provider: oauthWithField },
        attachTo: document.body,
    });
    await flushPromises();

    const input = document.body.querySelector('#connect-instance') as HTMLInputElement;
    input.value = 'my-instance';
    input.dispatchEvent(new Event('input'));

    const form = document.body.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    await flushPromises();

    expect(post).toHaveBeenCalledWith(`/integrations/${telegram.identifier}/auth-url`, expect.objectContaining({ instance: 'my-instance' }));
    expect(tab.location.href).toBe('https://oauth.example/authorize');
    expect(JSON.parse(localStorage.getItem('channel_connect_pending') ?? 'null')).toMatchObject({ provider: telegram.identifier, state: 's' });
});
