import { mount } from '@vue/test-utils';
import EmojiPicker from '../EmojiPicker.vue';
import { i18n } from '../../../i18n';

/**
 * Block 3. The picker itself only has to do three things well: open,
 * find the emoji the user means (in either language), and hand it back.
 * Where the character then lands in the text is `useCaretInsertion`'s
 * job and is tested there.
 */
function open() {
    const wrapper = mount(EmojiPicker, {
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

    return wrapper;
}

it('opens on the trigger and closes again', async () => {
    const wrapper = open();

    expect(wrapper.find('[data-testid="emoji-panel"]').exists()).toBe(false);

    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');
    expect(wrapper.find('[data-testid="emoji-panel"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="emoji-trigger"]').attributes('aria-expanded')).toBe('true');

    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');
    expect(wrapper.find('[data-testid="emoji-panel"]').exists()).toBe(false);
});

it('emits the chosen character', async () => {
    const wrapper = open();
    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');

    const cell = wrapper.findAll('[data-testid^="emoji-cell-"]')[0];
    await cell.trigger('click');

    expect(wrapper.emitted('select')?.[0]).toEqual([cell.text()]);
});

it('searches in russian, not only in english', async () => {
    // The dataset carries keywords in both languages precisely so a
    // Russian-speaking user is not forced to guess the English name.
    const wrapper = open();
    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');

    await wrapper.find('input[data-testid="emoji-search"]').setValue('ракета');

    const cells = wrapper.findAll('[data-testid^="emoji-cell-"]');
    expect(cells).toHaveLength(1);
    expect(cells[0].text()).toBe('🚀');
});

it('searches in english too', async () => {
    const wrapper = open();
    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');

    await wrapper.find('input[data-testid="emoji-search"]').setValue('rocket');

    expect(wrapper.findAll('[data-testid^="emoji-cell-"]')[0].text()).toBe('🚀');
});

it('says so when a search finds nothing, instead of showing an empty box', async () => {
    const wrapper = open();
    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');

    await wrapper.find('input[data-testid="emoji-search"]').setValue('zzzzzzz');

    expect(wrapper.findAll('[data-testid^="emoji-cell-"]')).toHaveLength(0);
    expect(wrapper.find('[data-testid="emoji-empty"]').exists()).toBe(true);
});

it('closes on escape and gives focus back to the trigger', async () => {
    // Losing focus into nowhere is how a keyboard user gets stranded:
    // the panel is gone and the next Tab starts from the top of the page.
    const wrapper = open();
    const trigger = wrapper.find('[data-testid="emoji-trigger"]');
    await trigger.trigger('click');

    await wrapper.find('[data-testid="emoji-panel"]').trigger('keydown', { key: 'Escape' });

    expect(wrapper.find('[data-testid="emoji-panel"]').exists()).toBe(false);
    expect(document.activeElement).toBe(trigger.element);

    wrapper.unmount();
});

it('moves focus across the grid with the arrow keys', async () => {
    const wrapper = open();
    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');

    const cells = wrapper.findAll('[data-testid^="emoji-cell-"]');
    await cells[0].trigger('keydown', { key: 'ArrowRight' });

    expect(document.activeElement).toBe(cells[1].element);

    wrapper.unmount();
});

it('exposes the grid to assistive technology as a grid', async () => {
    const wrapper = open();
    await wrapper.find('[data-testid="emoji-trigger"]').trigger('click');

    expect(wrapper.find('[data-testid="emoji-grid"]').attributes('role')).toBe('grid');
    expect(wrapper.find('[data-testid="emoji-trigger"]').attributes('aria-label')).toBeTruthy();
});
