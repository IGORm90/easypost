import { mount } from '@vue/test-utils';
import ProviderCard from '../ProviderCard.vue';
import { i18n } from '../../../i18n';
import { resolveProviderIcon } from '../../previews/registry';
import type { Provider } from '../../../stores/integrations';

/**
 * The "available networks" card — one per entry of `GET /api/providers`,
 * shown whether or not that network already has a connected channel
 * (several bots/accounts of the same network is a legitimate setup, so
 * this card never disappears; it grows a count instead).
 */
function provider(identifier: string, name: string): Provider {
    return {
        identifier,
        name,
        auth_type: 'manual',
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
}

// Deliberately built through resolveProviderIcon() rather than importing
// a named icon: this spec lives outside components/previews/, and
// providerIsolation.spec.ts fails the build on a provider name in any
// import path. The registry's own spec is where the identifier→icon map
// is asserted by name.
const known = provider('telegram', 'Telegram');
const unknown = provider('some-future-network', 'Some Future Network');

function mountCard(p: Provider, connectedCount = 0) {
    return mount(ProviderCard, { props: { provider: p, connectedCount } });
}

beforeEach(() => {
    i18n.global.locale.value = 'en';
});

it('renders the network name', () => {
    expect(mountCard(known).text()).toContain('Telegram');
});

it('renders the brand mark the registry resolves for this identifier', () => {
    expect(mountCard(known).findComponent(resolveProviderIcon(known.identifier)).exists()).toBe(true);
});

it('falls back to the generic mark for a network with no icon of its own', () => {
    // Every one of Block 5's planned networks renders through this branch
    // until its own mark is added — the card must not blow up meanwhile.
    expect(mountCard(unknown).findComponent(resolveProviderIcon(unknown.identifier)).exists()).toBe(true);
});

it('shows no count badge for a network nothing is connected to yet', () => {
    expect(mountCard(known, 0).text()).not.toContain(i18n.global.t('channels.available.connectedCount', { count: 0 }));
});

it('counts the channels already connected to this network', () => {
    expect(mountCard(known, 2).text()).toContain(i18n.global.t('channels.available.connectedCount', { count: 2 }));
});

it('emits connect when the button is pressed', async () => {
    const w = mountCard(known);
    await w.get('button').trigger('click');

    expect(w.emitted('connect')).toHaveLength(1);
});

it('gives the connect button an accessible name naming the network', () => {
    // The brand mark is aria-hidden, so "Connect" alone would read
    // identically on every card in the grid.
    expect(mountCard(known).get('button').attributes('aria-label')).toContain('Telegram');
});
