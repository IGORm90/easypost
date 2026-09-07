import { mount } from '@vue/test-utils';
import ChannelCard from '../ChannelCard.vue';
import { i18n } from '../../../i18n';
import { resolveProviderIcon } from '../../previews/registry';
import type { Integration } from '../../../stores/integrations';

const integration: Integration = {
    id: 'int-1',
    provider_identifier: 'telegram',
    name: 'Release notes',
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

function mountCard(overrides: Partial<Integration> = {}) {
    return mount(ChannelCard, { props: { integration: { ...integration, ...overrides }, provider: undefined } });
}

beforeEach(() => {
    i18n.global.locale.value = 'en';
});

it('shows the channel avatar the provider returned', () => {
    const w = mountCard({ picture: 'https://example.test/avatar.png' });

    expect(w.get('img').attributes('src')).toBe('https://example.test/avatar.png');
    expect(w.findComponent(resolveProviderIcon(integration.provider_identifier)).exists()).toBe(false);
});

it('falls back to the network brand mark when the channel has no avatar', () => {
    // Was a single-letter monogram, which said nothing a reader could
    // use — every Telegram channel showed the same "T".
    const w = mountCard({ picture: null });

    expect(w.find('img').exists()).toBe(false);
    expect(w.findComponent(resolveProviderIcon(integration.provider_identifier)).exists()).toBe(true);
});

it('still renders a channel whose network this build no longer ships', () => {
    const w = mountCard({ provider_identifier: 'some-removed-network', picture: null });

    expect(w.findComponent(resolveProviderIcon('some-removed-network')).exists()).toBe(true);
    expect(w.text()).toContain('Release notes');
});
