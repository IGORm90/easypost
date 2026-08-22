import { mount } from '@vue/test-utils';
import PreviewText from '../PreviewText.vue';

/**
 * Block 3: the preview must show what the reader will get, which means
 * rendering the marks the destination network supports and showing the
 * markers verbatim for the ones it does not.
 */
function render(text: string, marks: Parameters<typeof mount>[1] extends never ? never : string[] = []) {
    return mount(PreviewText, { props: { text, marks: marks as never } });
}

it('renders supported marks as real elements', () => {
    const wrapper = render('a **bold** word', ['BOLD']);

    expect(wrapper.find('.font-semibold').text()).toBe('bold');
    expect(wrapper.text()).toBe('a bold word');
});

it('shows the markers verbatim when the network cannot render them', () => {
    // A preview that bolds what will publish as asterisks is worse than
    // no preview: the user finds out from their own feed.
    const wrapper = render('a **bold** word', []);

    expect(wrapper.text()).toBe('a **bold** word');
    expect(wrapper.find('.font-semibold').exists()).toBe(false);
});

it('renders a markup link as an anchor', () => {
    const wrapper = render('see [the docs](https://example.test)', ['LINK']);
    const anchor = wrapper.find('a');

    expect(anchor.attributes('href')).toBe('https://example.test');
    expect(anchor.text()).toBe('the docs');
    expect(anchor.attributes('rel')).toContain('noopener');
});

it('never turns a javascript: target into a link', () => {
    const wrapper = render('[tap](javascript:alert(1))', ['LINK']);

    expect(wrapper.find('a').exists()).toBe(false);
    expect(wrapper.text()).toBe('[tap](javascript:alert(1))');
});

it('still auto-links a bare url', () => {
    const wrapper = render('read https://example.test now', ['BOLD']);

    // linkify() normalises through URL, which appends the root path.
    expect(wrapper.find('a').attributes('href')).toBe('https://example.test/');
});

it('does not auto-link a url inside code', () => {
    const wrapper = render('`https://example.test`', ['CODE']);

    expect(wrapper.find('a').exists()).toBe(false);
    expect(wrapper.find('code').text()).toBe('https://example.test');
});

it('renders post content as text, never as markup', () => {
    // The component's core safety property, unchanged by formatting:
    // there is no v-html anywhere, so a tag in post content is a tag the
    // reader sees, not one the browser runs.
    const wrapper = render('<img src=x onerror=alert(1)> **b**', ['BOLD']);

    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
});
