import { mount } from '@vue/test-utils';
import MediaGrid from '../MediaGrid.vue';
import { i18n } from '../../../i18n';
import type { MediaItem } from '../../../stores/media';

function image(overrides: Partial<MediaItem> = {}): MediaItem {
    return {
        id: 'med-a',
        url: 'https://cdn.example.com/a.jpg',
        thumbnail_url: 'https://cdn.example.com/a-thumb.jpg',
        mime: 'image/jpeg',
        size_bytes: 1024,
        width: 800,
        height: 600,
        original_filename: 'a.jpg',
        created_at: '',
        ...overrides,
    };
}

it('reserves layout space via aspect-ratio from width/height, before the image has loaded', () => {
    const w = mount(MediaGrid, { props: { items: [image()] } });

    const button = w.find('button');
    expect(button.attributes('style')).toContain('aspect-ratio: 800 / 600');
});

it('falls back to a fixed square box when width/height are null (video, or an unreadable image)', () => {
    const w = mount(MediaGrid, {
        props: { items: [image({ id: 'med-v', mime: 'video/mp4', width: null, height: null, thumbnail_url: null })] },
    });

    const button = w.find('button');
    // No inline aspect-ratio — the frame's own `aspect-square` utility class covers it instead.
    expect(button.attributes('style') ?? '').not.toContain('aspect-ratio');
    expect(button.classes()).toContain('aspect-square');
});

it('shows the empty message when there is nothing to show', () => {
    const w = mount(MediaGrid, { props: { items: [], emptyMessage: 'Nothing here' } });
    expect(w.text()).toContain('Nothing here');
});

it('never renders original_filename through v-html — text interpolation is escaped, and no extra element is injected', () => {
    const w = mount(MediaGrid, {
        props: { items: [image({ original_filename: '<img src=x onerror=alert(1)>.jpg' })] },
    });

    // The caption's text NODE is escaped by Vue's `{{ }}` interpolation
    // (serializes back to the entity form), and — critically — there is
    // still only the one legitimate `<img>` (the thumbnail itself): the
    // filename never produces a second, injected element.
    expect(w.find('span.truncate').html()).toContain('&lt;img src=x onerror=alert(1)&gt;.jpg');
    expect(w.findAll('img')).toHaveLength(1);
});

it('emits toggle with the item id when a selectable tile is clicked', async () => {
    const w = mount(MediaGrid, { props: { items: [image()], selectable: true } });

    await w.find('button').trigger('click');

    expect(w.emitted('toggle')).toEqual([['med-a']]);
});

it('shows the selection order badge matching position in selectedIds', () => {
    const w = mount(MediaGrid, {
        props: {
            items: [image({ id: 'med-a' }), image({ id: 'med-b' })],
            selectable: true,
            selectedIds: ['med-b', 'med-a'],
        },
    });

    const badges = w.findAll('span.rounded-full');
    // Tiles render in `items` order (med-a, med-b); their badges reflect
    // each one's position in `selectedIds` (['med-b', 'med-a']) — med-a
    // is 2nd, med-b is 1st.
    expect(badges[0].text()).toBe('2');
    expect(badges[1].text()).toBe('1');
});

it('emits delete with the item id when the delete button is clicked (deletable grid)', async () => {
    const w = mount(MediaGrid, { props: { items: [image()], deletable: true } });

    const deleteLabel = i18n.global.t('media.delete');
    await w.get(`button[aria-label="${deleteLabel}"]`).trigger('click');

    expect(w.emitted('delete')).toEqual([['med-a']]);
});

it('a pending (still-uploading) tile cannot be selected — review finding 3', async () => {
    const w = mount(MediaGrid, { props: { items: [image({ pending: true })], selectable: true } });

    const button = w.get('button');
    expect((button.element as HTMLButtonElement).disabled).toBe(true);

    await button.trigger('click');
    expect(w.emitted('toggle')).toBeFalsy();
});

it('a pending (still-uploading) tile offers no delete control — review "cheap and real" fix', () => {
    const w = mount(MediaGrid, { props: { items: [image({ pending: true })], deletable: true } });

    const deleteLabel = i18n.global.t('media.delete');
    expect(w.find(`button[aria-label="${deleteLabel}"]`).exists()).toBe(false);
});
