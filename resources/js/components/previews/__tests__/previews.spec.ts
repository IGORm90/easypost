import { mount } from '@vue/test-utils';
import TelegramPreview from '../TelegramPreview.vue';
import BlueskyPreview from '../BlueskyPreview.vue';
import RedditPreview from '../RedditPreview.vue';
import FacebookPagePreview from '../FacebookPagePreview.vue';
import InstagramPreview from '../InstagramPreview.vue';
import MetaThreadsPreview from '../MetaThreadsPreview.vue';
import GenericPreview from '../GenericPreview.vue';
import PreviewPane from '../PreviewPane.vue';

const ALL = [
    ['telegram', TelegramPreview],
    ['bluesky', BlueskyPreview],
    ['reddit', RedditPreview],
    ['facebook-page', FacebookPagePreview],
    ['instagram', InstagramPreview],
    ['meta-threads', MetaThreadsPreview],
    ['generic', GenericPreview],
] as const;

// --- Empty state ------------------------------------------------------
// A channel with no text yet must render something sane, not a broken
// card — every preview gets exactly one empty segment, the shape
// PostEditorModal.vue's newDraft() produces for a freshly-selected
// channel.

describe.each(ALL)('%s preview, empty draft', (_label, Component) => {
    it('renders a sane placeholder instead of a blank/broken card', () => {
        const w = mount(Component, {
            props: { channelName: 'My Channel', channelPicture: null, segments: [{ content: '' }] },
        });

        expect(w.text().length).toBeGreaterThan(0);
        expect(w.text()).not.toMatch(/undefined|NaN|\[object/);
    });
});

// --- Customer text is never HTML-injected ------------------------------

describe.each(ALL)('%s preview, hostile content', (_label, Component) => {
    it('renders markup-looking text as literal text, never as HTML', () => {
        const hostile = '<img src=x onerror=alert(1)> click javascript:alert(1) here';
        const w = mount(Component, {
            props: { channelName: 'My Channel', channelPicture: null, segments: [{ content: hostile }] },
        });

        // No element was actually created from the payload — only its
        // ESCAPED text representation appears in the rendered HTML (so
        // asserting the raw string contains "onerror=" would be a false
        // positive: that substring legitimately shows up inside the
        // escaped `&lt;img ... onerror=...&gt;` text node below).
        expect(w.find('img').exists()).toBe(false);
        expect(w.html()).not.toContain('<img src=x');
        // The raw text is still visible (escaped), not silently dropped.
        expect(w.text()).toContain('<img src=x onerror=alert(1)>');
        // No anchor was produced for the bare javascript: scheme.
        const jsLinks = w.findAll('a').filter((a) => (a.attributes('href') ?? '').startsWith('javascript:'));
        expect(jsLinks).toHaveLength(0);
    });

    it('a genuine https URL in the content becomes a safe, real link', () => {
        const w = mount(Component, {
            props: { channelName: 'My Channel', channelPicture: null, segments: [{ content: 'see https://example.com/x for more' }] },
        });

        const link = w.find('a');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('https://example.com/x');
    });
});

// --- Thread shape honesty ----------------------------------------------

it('Bluesky nests each reply under the previous one (a true linked-list chain)', () => {
    const w = mount(BlueskyPreview, {
        props: {
            channelName: 'My Bluesky',
            channelPicture: null,
            segments: [{ content: 'root post' }, { content: 'reply one' }, { content: 'reply two' }],
        },
    });

    expect(w.text()).toContain('root post');
    expect(w.text()).toContain('reply one');
    expect(w.text()).toContain('reply two');
    // One "Reply" label per non-root segment — the preview must not
    // imply three independent posts when the provider chains them.
    expect(w.findAll('p').filter((p) => p.text() === 'Reply')).toHaveLength(2);
});

it('Reddit nests each comment under the previous segment (submission + comment chain)', () => {
    const w = mount(RedditPreview, {
        props: {
            channelName: 'u/scheduler_bot',
            channelPicture: null,
            segments: [{ content: 'root submission' }, { content: 'comment one' }, { content: 'comment two' }],
        },
    });

    expect(w.text()).toContain('root submission');
    expect(w.text()).toContain('comment one');
    expect(w.text()).toContain('comment two');
    // One "Comment" label per non-root segment — mirrors RedditProvider::post(),
    // which publishes chain[0] as a submission and everything after as a
    // reply comment, never as a second detached submission.
    expect(w.findAll('p').filter((p) => p.text() === 'Comment')).toHaveLength(2);
});

it('Threads nests each reply under the previous one (a true linked-list chain)', () => {
    const w = mount(MetaThreadsPreview, {
        props: {
            channelName: '@brand',
            channelPicture: null,
            segments: [{ content: 'root post' }, { content: 'reply one' }, { content: 'reply two' }],
        },
    });

    expect(w.text()).toContain('root post');
    expect(w.text()).toContain('reply one');
    expect(w.text()).toContain('reply two');
    // One "Reply" label per non-root segment, mirroring ThreadsProvider::post(),
    // which threads every later segment onto the release id the previous
    // one just published, never as a second detached root.
    expect(w.findAll('p').filter((p) => p.text() === 'Reply')).toHaveLength(2);
});

// --- PreviewPane resolves via the registry, never by branching ---------

it('PreviewPane renders TelegramPreview for a telegram identifier', () => {
    const w = mount(PreviewPane, {
        props: { providerIdentifier: 'telegram', channelName: 'C', channelPicture: null, segments: [{ content: 'hi' }] },
    });

    expect(w.findComponent(TelegramPreview).exists()).toBe(true);
});

it('PreviewPane renders RedditPreview for a reddit identifier', () => {
    const w = mount(PreviewPane, {
        props: { providerIdentifier: 'reddit', channelName: 'C', channelPicture: null, segments: [{ content: 'hi' }] },
    });

    expect(w.findComponent(RedditPreview).exists()).toBe(true);
});

it('PreviewPane falls back to GenericPreview for an unknown identifier', () => {
    const w = mount(PreviewPane, {
        props: { providerIdentifier: 'mystery-network', channelName: 'C', channelPicture: null, segments: [{ content: 'hi' }] },
    });

    expect(w.findComponent(GenericPreview).exists()).toBe(true);
});
