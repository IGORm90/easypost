import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import Channels from '../Index.vue';
import en from '../../../i18n/en.json';
import { i18n } from '../../../i18n';
import type { Integration, Provider } from '../../../stores/integrations';
import ChannelLimitMeter from '../../../components/billing/ChannelLimitMeter.vue';

/**
 * The screen was one flat list of connected channels, and the catalogue
 * of what you *could* connect existed only inside the connect modal's
 * first step — so an operator with nothing connected saw a single
 * sentence and no way to learn the product supports anything. It is now
 * two blocks: the channels this organization holds, then every network
 * the build ships, each with its brand mark and a Connect button.
 *
 * A network keeps its available card after it is connected: uniqueness in
 * IntegrationService::connect() is (org, provider_identifier,
 * internal_id), so several bots or accounts of one network is a normal
 * setup, not a duplicate.
 */
const get = vi.fn();
const post = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

// The page under test is its two blocks, not the app chrome — AppLayout
// drags in the auth/organization stores, the router and the theme.
vi.mock('../../../layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

function provider(identifier: string, name: string): Provider {
    return {
        identifier,
        name,
        auth_type: 'manual',
        auth_fields: [{ key: 'token', type: 'password' }],
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
}

function integration(id: string, identifier: string, name: string): Integration {
    return {
        id,
        provider_identifier: identifier,
        name,
        picture: null,
        profile: null,
        settings: null,
        customer_name: null,
        group_name: null,
        disabled: false,
        refresh_needed: false,
        created_at: '2026-09-01T00:00:00Z',
        updated_at: '2026-09-01T00:00:00Z',
    };
}

const PROVIDERS = [provider('fake', 'Fake Network'), provider('other', 'Other Network')];

function respondWith(integrations: Integration[], providers: Provider[] = PROVIDERS): void {
    get.mockImplementation((url: string) => {
        if (url === '/integrations') {
            return Promise.resolve({ data: integrations });
        }

        if (url === '/providers') {
            return Promise.resolve({ data: providers });
        }

        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    localStorage.clear();
    i18n.global.locale.value = 'en';
    respondWith([]);
});

afterEach(() => {
    document.body.innerHTML = '';
});

async function mountPage() {
    const w = mount(Channels, { attachTo: document.body });
    await flushPromises();

    return w;
}

function cards(wrapper: Awaited<ReturnType<typeof mountPage>>) {
    return wrapper.findAll('[data-test="provider-card"]');
}

it('names both blocks, so the catalogue is not mistaken for the channel list', async () => {
    const w = await mountPage();

    expect(w.text()).toContain(en.channels.connected.title);
    expect(w.text()).toContain(en.channels.available.title);
});

// «Кобальт»: the plan's channel allowance sits next to the list, and is
// told to reload whenever the number of connected channels changes.
it('shows the channel allowance and keys its refresh to the channel count', async () => {
    respondWith([integration('i-1', 'fake', 'Release notes'), integration('i-2', 'fake', 'Support')]);
    const w = await mountPage();

    expect(w.findComponent(ChannelLimitMeter).props('refreshKey')).toBe(2);
});

// «Кобальт»: a channel that needs reconnecting is announced above the
// list — its scheduled posts will not go out — and only that one.
it('announces a channel that needs reconnecting above the list, and no other', async () => {
    respondWith([integration('i-1', 'fake', 'Release notes'), { ...integration('i-2', 'fake', 'Old page'), refresh_needed: true }]);
    const w = await mountPage();

    const banners = w.findAll('[data-test="reconnect-banner"]');
    expect(banners).toHaveLength(1);
    expect(banners[0].text()).toContain('Old page');
});

it('offers every network the build ships, even with nothing connected', async () => {
    const w = await mountPage();

    expect(get).toHaveBeenCalledWith('/providers');
    expect(cards(w)).toHaveLength(2);
    expect(w.text()).toContain('Fake Network');
    expect(w.text()).toContain('Other Network');
});

it('lists the connected channels above the catalogue', async () => {
    respondWith([integration('i-1', 'fake', 'Release notes')]);
    const w = await mountPage();

    expect(w.text()).toContain('Release notes');
    expect(w.text()).not.toContain(en.channels.empty);
});

it('keeps a network offerable once it is connected, and counts what is on it', async () => {
    respondWith([integration('i-1', 'fake', 'Release notes'), integration('i-2', 'fake', 'Support')]);
    const w = await mountPage();

    // Still two cards: connecting a second Telegram bot is a real setup,
    // not a duplicate, so the card is never hidden or disabled.
    expect(cards(w)).toHaveLength(2);
    expect(cards(w)[0].text()).toContain(i18n.global.t('channels.available.connectedCount', { count: 2 }));
    expect(cards(w)[1].text()).not.toContain(i18n.global.t('channels.available.connectedCount', { count: 0 }));
});

// Task 37: clicking a card used to reopen a second picker inside the
// modal — the very network you had just chosen was asked again. The
// card IS the picker now: it commits to its own provider, so the modal
// opens straight on that provider's credential form.
it('opens the clicked card\'s own credential form directly — no second picker', async () => {
    const w = await mountPage();

    await cards(w)[0].get('button').trigger('click');
    await flushPromises();

    // Modal.vue teleports to <body>, outside the mounted wrapper's root —
    // same reason ConnectProviderModal.spec.ts reads document.body.
    const dialog = document.body.querySelector('[role="dialog"]') as HTMLElement;
    expect(dialog.textContent).toContain('Fake Network');
    expect(dialog.querySelector('input[type="password"]')).not.toBeNull();
    expect(dialog.textContent).not.toContain('Other Network');
});

it('never opens a second picker for the other card either', async () => {
    const w = await mountPage();

    await cards(w)[1].get('button').trigger('click');
    await flushPromises();

    const dialog = document.body.querySelector('[role="dialog"]') as HTMLElement;
    expect(dialog.textContent).toContain('Other Network');
    expect(dialog.textContent).not.toContain('Fake Network');
});

it('opens the platform login in a new tab for a fast-connect provider, with no modal at all', async () => {
    const fastConnect = provider('fastnet', 'Fastnet');
    fastConnect.auth_type = 'oauth';
    fastConnect.auth_fields = [];
    respondWith([], [...PROVIDERS, fastConnect]);

    const tab = { location: { href: '' }, close: vi.fn() };
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window);
    post.mockResolvedValue({ data: { url: 'https://fastnet.example/login', state: 's', extra: {} } });

    const w = await mountPage();
    await cards(w)[2].get('button').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/integrations/fastnet/auth-url', {});
    expect(tab.location.href).toBe('https://fastnet.example/login');
    expect(document.body.querySelector('[role="dialog"]')).toBeNull();
});

it('shows a message and no crash when the browser blocks the login tab', async () => {
    const fastConnect = provider('fastnet', 'Fastnet');
    fastConnect.auth_type = 'oauth';
    fastConnect.auth_fields = [];
    respondWith([], [...PROVIDERS, fastConnect]);
    vi.spyOn(window, 'open').mockReturnValue(null);

    const w = await mountPage();
    await cards(w)[2].get('button').trigger('click');
    await flushPromises();

    expect(w.text()).toContain(en.channels.available.popupBlocked);
});
