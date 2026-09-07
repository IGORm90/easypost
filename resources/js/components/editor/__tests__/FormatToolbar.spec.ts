import { mount } from '@vue/test-utils';
import FormatToolbar from '../FormatToolbar.vue';
import { i18n } from '../../../i18n';
import type { TextMark } from '../../../stores/integrations';

/**
 * Block 3. The toolbar knows nothing about networks — only about the
 * marks it was handed, which come straight from a channel's
 * `capabilities.supportedMarks`. That is what keeps provider names out
 * of the editor entirely (see providerIsolation.spec.ts).
 */
function toolbar(marks: TextMark[]) {
    return mount(FormatToolbar, {
        props: { marks },
        global: { plugins: [i18n] },
    });
}

it('renders nothing at all when the network has no formatting', () => {
    // Not "renders disabled buttons": a greyed-out bold button on a
    // channel's tab promises something that will never happen.
    const wrapper = toolbar([]);

    expect(wrapper.find('[data-testid="format-toolbar"]').exists()).toBe(false);
    expect(wrapper.text()).toBe('');
});

it('renders exactly the marks it was given, in a stable order', () => {
    const wrapper = toolbar(['ITALIC', 'BOLD']);

    expect(wrapper.find('[data-testid="format-BOLD"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="format-ITALIC"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="format-STRIKE"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="format-CODE"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="format-LINK"]').exists()).toBe(false);
});

it('draws no link button for a network that would print the brackets', () => {
    // A network that renders four marks but not links: offering the
    // button would publish literal brackets.
    const wrapper = toolbar(['BOLD', 'ITALIC', 'STRIKE', 'CODE']);

    expect(wrapper.find('[data-testid="format-LINK"]').exists()).toBe(false);
});

it('emits the canonical markers for each mark', async () => {
    const wrapper = toolbar(['BOLD', 'ITALIC', 'STRIKE', 'CODE']);

    await wrapper.find('[data-testid="format-BOLD"]').trigger('click');
    await wrapper.find('[data-testid="format-ITALIC"]').trigger('click');
    await wrapper.find('[data-testid="format-STRIKE"]').trigger('click');
    await wrapper.find('[data-testid="format-CODE"]').trigger('click');

    expect(wrapper.emitted('wrap')).toEqual([
        ['**', '**'],
        ['*', '*'],
        ['~~', '~~'],
        ['`', '`'],
    ]);
});

it('asks for a url before emitting a link', async () => {
    const wrapper = toolbar(['LINK']);

    await wrapper.find('[data-testid="format-LINK"]').trigger('click');
    expect(wrapper.emitted('wrap')).toBeUndefined();

    await wrapper.find('input[data-testid="format-link-url"]').setValue('https://example.test');
    await wrapper.find('[data-testid="format-link-apply"]').trigger('click');

    expect(wrapper.emitted('wrap')?.[0]).toEqual(['[', '](https://example.test)']);
});

it('gives every button a text label, never an icon alone', () => {
    // color.md §4 in spirit: meaning may not rest on a glyph alone.
    const wrapper = toolbar(['BOLD', 'LINK']);

    expect(wrapper.find('[data-testid="format-BOLD"]').attributes('aria-label')).toBeTruthy();
    expect(wrapper.find('[data-testid="format-LINK"]').attributes('aria-label')).toBeTruthy();
});
