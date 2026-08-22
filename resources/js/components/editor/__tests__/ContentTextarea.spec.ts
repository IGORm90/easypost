import { mount } from '@vue/test-utils';
import ContentTextarea from '../ContentTextarea.vue';
import { i18n } from '../../../i18n';
import type { TextMark } from '../../../stores/integrations';

/**
 * Block 3 gave this component the two things that need to sit next to
 * the textarea because they need the textarea's own selection: the
 * emoji picker and the format toolbar.
 */
function textarea(props: { modelValue?: string; limit?: number; marks?: TextMark[]; emoji?: boolean } = {}) {
    return mount(ContentTextarea, {
        props: {
            modelValue: '',
            limit: 300,
            label: 'Post text',
            ...props,
        },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

it('shows no format toolbar for a network with no marks', () => {
    const wrapper = textarea({ marks: [] });

    expect(wrapper.find('[data-testid="format-toolbar"]').exists()).toBe(false);
});

it('shows the format toolbar for a network that has marks', () => {
    const wrapper = textarea({ marks: ['BOLD'] });

    expect(wrapper.find('[data-testid="format-BOLD"]').exists()).toBe(true);
});

it('shows the emoji trigger only when asked for', () => {
    expect(textarea({ emoji: false }).find('[data-testid="emoji-trigger"]').exists()).toBe(false);
    expect(textarea({ emoji: true }).find('[data-testid="emoji-trigger"]').exists()).toBe(true);
});

it('wraps the current selection when a format button is pressed', async () => {
    const wrapper = textarea({ modelValue: 'make me bold', marks: ['BOLD'] });
    const field = wrapper.find('textarea').element as HTMLTextAreaElement;
    field.setSelectionRange(8, 12);

    await wrapper.find('[data-testid="format-BOLD"]').trigger('click');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['make me **bold**']);
});

it('inserts a chosen emoji at the caret, not at the end', async () => {
    const wrapper = textarea({ modelValue: 'hi there', emoji: true });
    const field = wrapper.find('textarea').element as HTMLTextAreaElement;
    field.setSelectionRange(2, 2);

    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');
    await wrapper.find('[data-testid="emoji-cell-🚀"]').trigger('click');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['hi🚀 there']);
});

it('applies bold on ctrl+b, but only when the channel supports it', async () => {
    const wrapper = textarea({ modelValue: 'bold me', marks: ['BOLD'] });
    const field = wrapper.find('textarea');
    (field.element as HTMLTextAreaElement).setSelectionRange(0, 4);

    await field.trigger('keydown', { key: 'b', ctrlKey: true });

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['**bold** me']);

    const plain = textarea({ modelValue: 'bold me', marks: [] });
    await plain.find('textarea').trigger('keydown', { key: 'b', ctrlKey: true });

    expect(plain.emitted('update:modelValue')).toBeUndefined();
});

it('counts formatting markers toward the character limit', () => {
    // Deliberate and documented: the stored body is what is counted, and
    // the network counts the rendered text, so this errs on the safe
    // side — see PostValidationService's docblock, which says the same
    // thing about the server-side count.
    const wrapper = textarea({ modelValue: '**bold**', limit: 300 });

    expect(wrapper.text()).toContain('8 / 300');
});
