import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PostSettingsFields from '../PostSettingsFields.vue';
import { useIntegrationsStore, type ProviderPostSettingField } from '../../../stores/integrations';

/**
 * Block 5.0.6: some networks need choices made per POST — a subreddit, a
 * title, a flair — and the SPA is not allowed to know which network is
 * which (resources/js/__tests__/providerIsolation.spec.ts). So there is
 * one component, and it renders whatever `GET /api/providers` declared.
 */
const FIELDS: ProviderPostSettingField[] = [
    { key: 'topic', type: 'select', required: true, option: 'topics' },
    { key: 'title', type: 'text', required: true },
    { key: 'flavour', type: 'select', required: false, option: 'flavours', dependsOn: 'topic' },
];

function mountFields(fields = FIELDS, settings: Record<string, unknown> = {}) {
    return mount(PostSettingsFields, {
        props: { fields, integrationId: 'int-1', modelValue: settings },
    });
}

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('rendering', () => {
    it('renders nothing at all for a provider that declares no post settings', () => {
        const w = mountFields([]);

        expect(w.find('[data-testid="post-settings"]').exists()).toBe(false);
    });

    it('renders one control per declared field, in the declared order', async () => {
        const store = useIntegrationsStore();
        store.fetchOptions = vi.fn().mockResolvedValue([]);

        const w = mountFields();
        await flushPromises();

        const controls = w.findAll('[data-field]');
        expect(controls.map((c) => c.attributes('data-field'))).toEqual(['topic', 'title', 'flavour']);
        expect(controls[0].element.tagName).toBe('SELECT');
        expect(controls[1].element.tagName).toBe('INPUT');
    });

    it('gives every control a real label, not a placeholder', async () => {
        const store = useIntegrationsStore();
        store.fetchOptions = vi.fn().mockResolvedValue([]);

        const w = mountFields();
        await flushPromises();

        for (const field of FIELDS) {
            const label = w.find(`label[for="post-setting-${field.key}"]`);
            expect(label.exists()).toBe(true);
            expect(label.text().trim().length).toBeGreaterThan(0);
        }
    });
});

describe('options', () => {
    it('loads the list an independent select picks from', async () => {
        const store = useIntegrationsStore();
        store.fetchOptions = vi.fn().mockResolvedValue([{ value: 'jazz', label: 'Jazz' }]);

        const w = mountFields();
        await flushPromises();

        expect(store.fetchOptions).toHaveBeenCalledWith('int-1', 'topics', {});
        expect(w.find('[data-field="topic"]').text()).toContain('Jazz');
    });

    it('asks for a dependent list with its parent value as context', async () => {
        const store = useIntegrationsStore();
        store.fetchOptions = vi.fn().mockResolvedValue([]);

        mountFields(FIELDS, { topic: 'jazz' });
        await flushPromises();

        expect(store.fetchOptions).toHaveBeenCalledWith('int-1', 'flavours', { topic: 'jazz' });
    });

    it('reloads a dependent list and clears its value when the parent changes', async () => {
        const store = useIntegrationsStore();
        // Each list is answered with its own values: a select cannot take
        // a value it has no option for, so a one-size-fits-all mock would
        // silently make this test about nothing.
        store.fetchOptions = vi.fn().mockImplementation((_id: string, option: string) =>
            Promise.resolve(
                option === 'topics'
                    ? [
                          { value: 'jazz', label: 'Jazz' },
                          { value: 'folk', label: 'Folk' },
                      ]
                    : [{ value: 'jazz-smooth', label: 'Smooth' }],
            ),
        );

        const w = mountFields(FIELDS, { topic: 'jazz', flavour: 'jazz-smooth' });
        await flushPromises();

        await w.find('[data-field="topic"]').setValue('folk');
        await flushPromises();

        // A flair belonging to the previously chosen subreddit is not a
        // stale nicety — it is a publish the network rejects.
        const emitted = w.emitted('update:modelValue') ?? [];
        const latest = emitted[emitted.length - 1][0] as Record<string, unknown>;
        expect(latest.topic).toBe('folk');
        expect(latest.flavour).toBe('');
        expect(store.fetchOptions).toHaveBeenLastCalledWith('int-1', 'flavours', { topic: 'folk' });
    });

    it('keeps the form usable when an option list fails to load', async () => {
        const store = useIntegrationsStore();
        store.fetchOptions = vi.fn().mockRejectedValue(new Error('network is down'));

        const w = mountFields();
        await flushPromises();

        expect(w.find('[role="alert"]').exists()).toBe(true);
        // The text field has nothing to do with the failing list.
        expect(w.find('[data-field="title"]').exists()).toBe(true);
    });
});

describe('values', () => {
    it('emits the edited settings without touching keys it does not own', async () => {
        const store = useIntegrationsStore();
        store.fetchOptions = vi.fn().mockResolvedValue([]);

        // A post saved before this provider declared its fields can carry
        // keys nothing renders. Dropping them here would silently discard
        // data on every edit.
        const w = mountFields(FIELDS, { legacy_key: 'kept' });
        await flushPromises();

        await w.find('[data-field="title"]').setValue('hello');

        const emitted = w.emitted('update:modelValue') ?? [];
        const latest = emitted[emitted.length - 1][0] as Record<string, unknown>;
        expect(latest.title).toBe('hello');
        expect(latest.legacy_key).toBe('kept');
    });

    it('marks a required field as required for assistive technology', async () => {
        const store = useIntegrationsStore();
        store.fetchOptions = vi.fn().mockResolvedValue([]);

        const w = mountFields();
        await flushPromises();

        expect(w.find('[data-field="title"]').attributes('required')).toBeDefined();
        expect(w.find('[data-field="flavour"]').attributes('required')).toBeUndefined();
    });
});
