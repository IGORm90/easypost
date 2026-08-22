import { mount } from '@vue/test-utils';
import PostList from '../PostList.vue';
import { i18n } from '../../../i18n';
import type { PostListRow } from '../../../stores/posts';

function row(overrides: Partial<PostListRow> = {}): PostListRow {
    return {
        id: 'group-1',
        state: 'QUEUE',
        publish_date: '2026-08-20T09:00:00.000000Z',
        share_token: null,
        preview: 'Launch announcement',
        channels: [{ id: 'int-1', provider_identifier: 'telegram', name: 'Team channel', picture: null }],
        tags: [],
        comments_count: 0,
        ...overrides,
    };
}

beforeEach(() => {
    i18n.global.locale.value = 'en';
});

function mountList(rows: PostListRow[], timezone = 'UTC') {
    return mount(PostList, {
        props: { entries: rows, timezone, stateFilter: 'all' },
        global: { plugins: [i18n] },
    });
}

/*
 * The runner's TZ is pinned to America/Chicago, so a component grouping
 * by the browser's own day would put this 23:30Z post under 2026-08-20
 * and pass a naive assertion. Sydney is +10, which makes the correct
 * answer a different date from both UTC and Chicago.
 */
it('groups rows by the day they fall on in the viewer timezone', () => {
    const w = mountList(
        [
            row({ id: 'a', publish_date: '2026-08-20T23:30:00.000000Z' }),
            row({ id: 'b', publish_date: '2026-08-21T02:00:00.000000Z' }),
        ],
        'Australia/Sydney',
    );

    // Both instants are the 21st in Sydney — one heading, two rows.
    const headings = w.findAll('[data-test="day-heading"]');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toContain('21');
    expect(w.findAll('[data-test="post-row"]')).toHaveLength(2);
});

it('names the state in text, not only in colour', () => {
    const w = mountList([row({ state: 'PUBLISHED' })]);

    expect(w.text()).toContain('Published');
});

it('offers no delete action on a published row', async () => {
    const published = mountList([row({ state: 'PUBLISHED' })]);
    await published.get('[aria-haspopup="menu"]').trigger('click');
    expect(published.text()).not.toContain('Remove');

    const scheduled = mountList([row({ state: 'QUEUE' })]);
    await scheduled.get('[aria-haspopup="menu"]').trigger('click');
    expect(scheduled.text()).toContain('Remove');
});

it('emits edit with the group id when a row is clicked', async () => {
    const w = mountList([row()]);

    await w.get('[data-test="post-row"] button').trigger('click');

    expect(w.emitted('edit')?.[0]).toEqual(['group-1']);
});

it('shows the channel names and the comment count', () => {
    const w = mountList([
        row({
            comments_count: 3,
            channels: [
                { id: 'int-1', provider_identifier: 'telegram', name: 'Team channel', picture: null },
                { id: 'int-2', provider_identifier: 'mastodon', name: 'Public feed', picture: null },
            ],
        }),
    ]);

    expect(w.text()).toContain('Team channel');
    expect(w.text()).toContain('Public feed');
    expect(w.text()).toContain('3');
});

it('explains an empty result in terms of the filter that produced it', () => {
    const drafts = mount(PostList, {
        props: { entries: [], timezone: 'UTC', stateFilter: 'DRAFT' },
        global: { plugins: [i18n] },
    });
    expect(drafts.text()).toContain('No drafts');

    const everything = mount(PostList, {
        props: { entries: [], timezone: 'UTC', stateFilter: 'all' },
        global: { plugins: [i18n] },
    });
    expect(everything.text()).toContain('No posts yet');
});

it('survives a row that lost its publish date instead of rendering an invalid day', () => {
    const w = mountList([row({ publish_date: null })]);

    expect(w.findAll('[data-test="post-row"]')).toHaveLength(1);
    expect(w.text()).toContain('No date');
    expect(w.text()).not.toContain('Invalid');
});
