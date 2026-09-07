import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import ChannelSettingsDrawer from '../ChannelSettingsDrawer.vue';
import { i18n } from '../../../i18n';
import type { Integration, Provider } from '../../../stores/integrations';

const get = vi.fn();
const put = vi.fn();

vi.mock('../../../api/client', () => ({
    api: {
        get: (...args: unknown[]) => get(...args),
        put: (...args: unknown[]) => put(...args),
    },
}));

const capabilities = {
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
};

function provider(overrides: Partial<Provider> = {}): Provider {
    return {
        identifier: 'picker-provider',
        name: 'Picker',
        auth_type: 'oauth',
        capabilities,
        auth_fields: [],
        settings_fields: [{ key: 'channel_id', option: 'channels' }],
        post_settings: [],
        ...overrides,
    };
}

function integration(overrides: Partial<Integration> = {}): Integration {
    return {
        id: 'int-1',
        provider_identifier: 'picker-provider',
        name: 'Workspace',
        picture: null,
        profile: null,
        settings: null,
        customer_name: null,
        group_name: null,
        disabled: false,
        refresh_needed: false,
        time_slots: [],
        created_at: '',
        updated_at: '',
        ...overrides,
    };
}

async function mountDrawer(providers: Provider[], value: Integration) {
    const { useIntegrationsStore } = await import('../../../stores/integrations');
    const store = useIntegrationsStore();
    store.$patch((state) => {
        state.providers = providers;
        state.list = [value];
    });

    const w = mount(ChannelSettingsDrawer, {
        props: { open: true, integration: value, timezone: 'UTC' },
        // Modal.vue renders through <Teleport to="body">; stubbing it keeps
        // the markup inside the wrapper where the queries below can see it.
        global: { plugins: [i18n], stubs: { teleport: true } },
    });
    await flushPromises();

    return w;
}

beforeEach(() => {
    setActivePinia(createPinia());
    i18n.global.locale.value = 'en';
    get.mockReset();
    put.mockReset();
    get.mockResolvedValue({
        data: {
            options: [
                { value: 'C1', label: 'general' },
                { value: 'C2', label: 'announcements' },
            ],
        },
    });
    put.mockResolvedValue({ data: integration() });
});

// Final review, Critical 4: a provider whose connect flow cannot know
// which destination to post to declares the setting and the option list
// it is picked from (GET /api/providers -> settings_fields). Nothing in
// the SPA read that, nothing called /options/, and the PUT body carried
// no `settings` key at all — so the value could never be set and every
// post on such a channel failed. The picker is driven by the DECLARED
// capability, never by the provider's identifier.
it('loads the declared option list and renders a picker for it', async () => {
    const w = await mountDrawer([provider()], integration());

    expect(get).toHaveBeenCalledWith('/integrations/int-1/options/channels');

    const select = w.get('select');
    expect(select.findAll('option').map((o) => o.text())).toEqual([
        i18n.global.t('channels.settings.chooseOption'),
        'general',
        'announcements',
    ]);
});

it('preselects the value the channel already has', async () => {
    const w = await mountDrawer([provider()], integration({ settings: { channel_id: 'C2' } }));

    expect((w.get('select').element as HTMLSelectElement).value).toBe('C2');
});

// Final review round 2: the placeholder option was offered even on a
// channel that already has a value, and choosing it did nothing at all —
// the key is omitted from the body, `IntegrationService::update()`
// merges, the old value survives, and the `props.integration` watch snaps
// the select back to it. The user is shown an action, performs it, and is
// told nothing. It cannot be made to work either: the provider declares
// `channel_id` as `required`, so `settings: {channel_id: null}` is a 422,
// and a channel with no destination cannot publish anyway. So the action
// is withdrawn rather than faked.
it('does not offer the placeholder option once the channel has a value', async () => {
    const w = await mountDrawer([provider()], integration({ settings: { channel_id: 'C2' } }));

    expect(w.get('select').findAll('option').map((o) => o.text())).toEqual(['general', 'announcements']);
});

it('offers the placeholder while nothing is chosen, and drops it after a choice', async () => {
    const w = await mountDrawer([provider()], integration());

    expect(w.get('select').findAll('option')[0].text()).toBe(i18n.global.t('channels.settings.chooseOption'));

    await w.get('select').setValue('C2');

    expect(w.get('select').findAll('option').map((o) => o.text())).toEqual(['general', 'announcements']);
});

it('sends the picked value in the PUT body', async () => {
    const w = await mountDrawer([provider()], integration());

    await w.get('select').setValue('C2');
    await w.get('form').trigger('submit');
    await flushPromises();

    expect(put).toHaveBeenCalledWith('/integrations/int-1', expect.objectContaining({ settings: { channel_id: 'C2' } }));
});

// Batch 2 changed this contract: `settings` is validated per provider and
// `settings: null` is no longer accepted at all. A channel with nothing to
// pick must therefore omit the key entirely, not send an empty one.
it('omits settings entirely for a provider that declares no picker, and asks for no options', async () => {
    const w = await mountDrawer([provider({ settings_fields: [] })], integration());

    expect(get).not.toHaveBeenCalled();
    expect(w.find('select').exists()).toBe(false);

    await w.get('form').trigger('submit');
    await flushPromises();

    expect(put).toHaveBeenCalledTimes(1);
    expect(Object.keys(put.mock.calls[0][1] as object)).not.toContain('settings');
});

it('leaves the rest of the form usable when the option list cannot be loaded', async () => {
    get.mockRejectedValueOnce(new Error('the network is down'));

    const w = await mountDrawer([provider()], integration());

    expect(w.find('[role="alert"]').exists()).toBe(true);

    await w.get('form').trigger('submit');
    await flushPromises();

    // Nothing was picked, so nothing is claimed to have been picked: the
    // key is omitted rather than sent empty, which the API would reject.
    expect(Object.keys(put.mock.calls[0][1] as object)).not.toContain('settings');
});
