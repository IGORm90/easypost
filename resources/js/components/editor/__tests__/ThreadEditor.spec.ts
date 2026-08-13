import { mount } from '@vue/test-utils';
import ThreadEditor from '../ThreadEditor.vue';
import type { ProviderCapabilities } from '../../../stores/integrations';

function capabilities(overrides: Partial<ProviderCapabilities> = {}): ProviderCapabilities {
    return {
        maxCharacters: 300,
        maxImages: 4,
        maxVideos: 1,
        supportsThreads: true,
        supportsScheduling: true,
        allowedMimeTypes: ['image/png'],
        maxFileSizeBytes: 5_000_000,
        allowsMixedMedia: false,
        maxThreadLength: 25,
        requiresAltText: false,
        ...overrides,
    };
}

function segments(...contents: string[]) {
    return contents.map((content) => ({ content, media_ids: [] }));
}

it('clicking "add" appends a segment to the model and emits the longer array', async () => {
    const w = mount(ThreadEditor, {
        props: { modelValue: segments('hello'), capabilities: capabilities(), label: 'Bluesky' },
    });

    await w.get('[data-testid="thread-add"]').trigger('click');

    const emitted = w.emitted('update:modelValue');
    expect(emitted).toBeTruthy();
    const next = emitted![0][0] as Array<{ content: string; media_ids: string[] }>;
    expect(next).toHaveLength(2);
    expect(next[0].content).toBe('hello');
    expect(next[1]).toEqual({ content: '', media_ids: [] });
});

it('clicking "remove" on a reply splices it out and emits a shorter array', async () => {
    const w = mount(ThreadEditor, {
        props: { modelValue: segments('root', 'reply one', 'reply two'), capabilities: capabilities(), label: 'Bluesky' },
    });

    const removeButtons = w.findAll('[data-testid^="thread-remove-"]');
    expect(removeButtons).toHaveLength(3);
    await removeButtons[1].trigger('click');

    const next = w.emitted('update:modelValue')![0][0] as Array<{ content: string }>;
    expect(next).toEqual([{ content: 'root', media_ids: [] }, { content: 'reply two', media_ids: [] }]);
});

it('a lone root segment has no remove button — a thread can never be emptied to zero', () => {
    const w = mount(ThreadEditor, {
        props: { modelValue: segments('only'), capabilities: capabilities(), label: 'Bluesky' },
    });

    expect(w.find('[data-testid^="thread-remove-"]').exists()).toBe(false);
});

it("the counter reflects the channel's own maxCharacters and turns red past it", async () => {
    const short = mount(ThreadEditor, {
        props: { modelValue: segments('a'.repeat(50)), capabilities: capabilities({ maxCharacters: 300 }), label: 'Bluesky' },
    });
    expect(short.text()).toContain('50 / 300');
    expect(short.find('[data-testid="counter-0"]').classes().join(' ')).not.toMatch(/danger/);

    const over = mount(ThreadEditor, {
        props: { modelValue: segments('a'.repeat(2500)), capabilities: capabilities({ maxCharacters: 2000 }), label: 'Discord' },
    });
    expect(over.text()).toContain('2500 / 2000');
    expect(over.find('[data-testid="counter-0"]').classes().join(' ')).toMatch(/danger/);
    // Never colour alone: an explicit textual/iconic signal must also be present.
    expect(over.find('[data-testid="counter-0"]').find('svg').exists()).toBe(true);
});

it('a different channel with a different maxCharacters drives its own counter, not a shared constant', () => {
    const telegram = mount(ThreadEditor, {
        props: { modelValue: segments('a'.repeat(1000)), capabilities: capabilities({ maxCharacters: 4096 }), label: 'Telegram' },
    });
    expect(telegram.text()).toContain('1000 / 4096');
    expect(telegram.find('[data-testid="counter-0"]').classes().join(' ')).not.toMatch(/danger/);
});

it('a signature is included in the counter of the last segment only', () => {
    const w = mount(ThreadEditor, {
        props: {
            modelValue: segments('root text', 'reply text'),
            capabilities: capabilities({ maxCharacters: 300 }),
            signatureLength: 20,
            label: 'Bluesky',
        },
    });

    // "root text" = 9 chars, no signature on a non-last segment.
    expect(w.find('[data-testid="counter-0"]').text()).toContain('9 / 300');
    // "reply text" = 10 chars + 20 signature chars = 30, on the last segment.
    expect(w.find('[data-testid="counter-1"]').text()).toContain('30 / 300');
});

it('the add button is disabled and explained when the channel does not support threads (Discord)', () => {
    const w = mount(ThreadEditor, {
        props: {
            modelValue: segments('only'),
            capabilities: capabilities({ supportsThreads: false, maxThreadLength: null }),
            label: 'Discord',
        },
    });

    const button = w.get('[data-testid="thread-add"]');
    expect((button.element as HTMLButtonElement).disabled).toBe(true);
    expect(w.text()).toMatch(/thread/i);
});

it('the add button disables once maxThreadLength is reached', () => {
    const w = mount(ThreadEditor, {
        props: {
            modelValue: segments('a', 'b', 'c'),
            capabilities: capabilities({ supportsThreads: true, maxThreadLength: 3 }),
            label: 'Mastodon',
        },
    });

    expect((w.get('[data-testid="thread-add"]').element as HTMLButtonElement).disabled).toBe(true);
});

it('typing in a segment updates the model at that index without touching the others', async () => {
    const w = mount(ThreadEditor, {
        props: { modelValue: segments('root', 'reply'), capabilities: capabilities(), label: 'Bluesky' },
    });

    const textareas = w.findAll('textarea');
    await textareas[1].setValue('edited reply');

    const next = w.emitted('update:modelValue')![0][0] as Array<{ content: string }>;
    expect(next).toEqual([{ content: 'root', media_ids: [] }, { content: 'edited reply', media_ids: [] }]);
});
