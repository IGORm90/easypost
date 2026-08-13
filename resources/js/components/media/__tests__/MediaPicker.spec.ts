import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import MediaPicker from '../MediaPicker.vue';
import { useMediaStore, type MediaItem } from '../../../stores/media';
import type { ProviderCapabilities } from '../../../stores/integrations';

const get = vi.fn();

vi.mock('../../../api/client', () => ({
    api: { get: (...args: unknown[]) => get(...args) },
}));

beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
});

function image(id: string, overrides: Partial<MediaItem> = {}): MediaItem {
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
        ...overrides,
    };
}

function capabilities(overrides: Partial<ProviderCapabilities> = {}): ProviderCapabilities {
    return {
        maxCharacters: 300,
        maxImages: 4,
        maxVideos: 1,
        supportsThreads: true,
        supportsScheduling: true,
        allowedMimeTypes: ['image/jpeg'],
        maxFileSizeBytes: 5_000_000,
        allowsMixedMedia: false,
        maxThreadLength: 25,
        requiresAltText: false,
        ...overrides,
    };
}

// Modal teleports its content to <body>, outside the mounted wrapper's
// own root element — @vue/test-utils' wrapper.find()/get() only search
// the wrapper's own subtree, so every interaction here goes through
// `document.body` directly instead (same fix ThreadEditor.spec.ts's
// "opens the picker" test needed for the same reason).
async function click(selector: string): Promise<void> {
    const el = document.body.querySelector(selector) as HTMLButtonElement | null;
    if (!el) {
        throw new Error(`not found: ${selector}`);
    }
    el.click();
    await flushPromises();
}

async function clickAll(selector: string): Promise<HTMLButtonElement[]> {
    return Array.from(document.body.querySelectorAll(selector));
}

it('selection order is media_ids order: the Nth tile clicked becomes the Nth id', async () => {
    const store = useMediaStore();
    store.$patch({ items: [image('a'), image('b'), image('c')], hasMore: false });

    const w = mount(MediaPicker, { props: { open: true, modelValue: [] }, attachTo: document.body });
    await flushPromises();

    const buttons = await clickAll('button[aria-pressed]');
    buttons[2].click(); // c
    await flushPromises();
    buttons[0].click(); // a
    await flushPromises();

    await click('[data-testid="media-picker-confirm"]');

    expect(w.emitted('update:modelValue')![0][0]).toEqual(['c', 'a']);
    w.unmount();
});

it('deselecting and reselecting a tile moves it to the end of the order', async () => {
    const store = useMediaStore();
    store.$patch({ items: [image('a'), image('b')], hasMore: false });

    const w = mount(MediaPicker, { props: { open: true, modelValue: [] }, attachTo: document.body });
    await flushPromises();

    const buttons = await clickAll('button[aria-pressed]');
    buttons[0].click(); // a
    await flushPromises();
    buttons[1].click(); // b -> [a, b]
    await flushPromises();
    buttons[0].click(); // deselect a -> [b]
    await flushPromises();
    buttons[0].click(); // reselect a -> [b, a]
    await flushPromises();

    await click('[data-testid="media-picker-confirm"]');

    expect(w.emitted('update:modelValue')![0][0]).toEqual(['b', 'a']);
    w.unmount();
});

it('cancelling discards any selection made during this session — modelValue is not emitted with the picks', async () => {
    const store = useMediaStore();
    store.$patch({ items: [image('a')], hasMore: false });

    const w = mount(MediaPicker, { props: { open: true, modelValue: ['pre-existing'] }, attachTo: document.body });
    await flushPromises();

    await click('button[aria-pressed]');
    await click('[data-testid="media-picker-cancel"]');

    expect(w.emitted('update:modelValue')).toBeFalsy();
    expect(w.emitted('close')).toBeTruthy();
    w.unmount();
});

it("blocks selecting past this channel's own maxImages", async () => {
    const store = useMediaStore();
    store.$patch({ items: [image('a'), image('b'), image('c')], hasMore: false });

    const w = mount(MediaPicker, {
        props: { open: true, modelValue: [], capabilities: capabilities({ maxImages: 2 }) },
        attachTo: document.body,
    });
    await flushPromises();

    const buttons = await clickAll('button[aria-pressed]');
    buttons[0].click();
    await flushPromises();
    buttons[1].click();
    await flushPromises();
    buttons[2].click(); // blocked: already at maxImages
    await flushPromises();

    await click('[data-testid="media-picker-confirm"]');

    expect(w.emitted('update:modelValue')![0][0]).toEqual(['a', 'b']);
    w.unmount();
});

it('re-opening with a different modelValue re-stages selection from it, not from the previous session', async () => {
    const store = useMediaStore();
    store.$patch({ items: [image('a'), image('b')], hasMore: false });

    const w = mount(MediaPicker, { props: { open: true, modelValue: ['a'] }, attachTo: document.body });
    await flushPromises();

    await w.setProps({ open: false });
    await w.setProps({ open: true, modelValue: ['b'] });
    await flushPromises();

    await click('[data-testid="media-picker-confirm"]');

    expect(w.emitted('update:modelValue')![0][0]).toEqual(['b']);
    w.unmount();
});
