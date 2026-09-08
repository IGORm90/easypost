import { createPinia, setActivePinia } from 'pinia';
import { PopupBlockedError, useChannelConnect } from '../useChannelConnect';
import type { Provider } from '../../stores/integrations';

const post = vi.fn();

vi.mock('../../api/client', () => ({
    api: { post: (...args: unknown[]) => post(...args) },
}));

/**
 * The oauth-connect half of the flow moved out of ConnectProviderModal.vue
 * because it is now needed from two places: the modal (an oauth provider
 * that declares fields to collect first) and Channels/Index.vue directly
 * (a fast-connect oauth provider that declares none — see that page's
 * `beginConnect()`).
 */
const fastnet: Provider = {
    identifier: 'fastnet',
    name: 'Fastnet',
    auth_type: 'oauth',
    auth_fields: [],
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

function fakeTab() {
    return { location: { href: '' }, close: vi.fn() };
}

beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    localStorage.clear();
    vi.useRealTimers();
});

it('opens the tab before the auth-url request settles, so the user gesture is not spent waiting on the network', async () => {
    const order: string[] = [];
    const tab = fakeTab();
    const open = vi.spyOn(window, 'open').mockImplementation(() => {
        order.push('open');

        return tab as unknown as Window;
    });
    post.mockImplementation(() => {
        order.push('post');

        return Promise.resolve({ data: { url: 'https://fastnet.example/login', state: 's', extra: {} } });
    });

    const { startOAuthConnect } = useChannelConnect();
    await startOAuthConnect(fastnet, {});

    expect(order).toEqual(['open', 'post']);
    expect(open).toHaveBeenCalledWith('', '_blank', 'noopener');
    expect(tab.location.href).toBe('https://fastnet.example/login');
});

it('stores the pending connect record with a timestamp, keyed for the callback tab to read', async () => {
    vi.spyOn(window, 'open').mockReturnValue(fakeTab() as unknown as Window);
    post.mockResolvedValueOnce({ data: { url: 'https://fastnet.example/login', state: 's', extra: { instance: 'x' } } });
    vi.useFakeTimers().setSystemTime(new Date('2026-09-08T00:00:00Z'));

    const { startOAuthConnect } = useChannelConnect();
    await startOAuthConnect(fastnet, {});

    const stored = JSON.parse(localStorage.getItem('channel_connect_pending') ?? 'null');
    expect(stored).toEqual({
        provider: 'fastnet',
        state: 's',
        extra: { instance: 'x' },
        created_at: Date.parse('2026-09-08T00:00:00Z'),
    });
});

it('closes the opened tab and rethrows when the auth-url request fails', async () => {
    const tab = fakeTab();
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window);
    const failure = { isAxiosError: true, response: { status: 403, data: {} } };
    post.mockRejectedValueOnce(failure);

    const { startOAuthConnect } = useChannelConnect();

    await expect(startOAuthConnect(fastnet, {})).rejects.toBe(failure);
    expect(tab.close).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem('channel_connect_pending')).toBeNull();
});

it('throws PopupBlockedError instead of calling the API when the browser blocked the popup', async () => {
    vi.spyOn(window, 'open').mockReturnValue(null);

    const { startOAuthConnect } = useChannelConnect();

    await expect(startOAuthConnect(fastnet, {})).rejects.toBeInstanceOf(PopupBlockedError);
    expect(post).not.toHaveBeenCalled();
});
