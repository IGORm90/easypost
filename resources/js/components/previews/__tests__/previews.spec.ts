import { mount } from '@vue/test-utils';
import TelegramPreview from '../TelegramPreview.vue';
import MastodonPreview from '../MastodonPreview.vue';
import BlueskyPreview from '../BlueskyPreview.vue';
import DiscordPreview from '../DiscordPreview.vue';
import SlackPreview from '../SlackPreview.vue';
import GenericPreview from '../GenericPreview.vue';
import PreviewPane from '../PreviewPane.vue';

const ALL = [
    ['telegram', TelegramPreview],
    ['mastodon', MastodonPreview],
    ['bluesky', BlueskyPreview],
    ['discord', DiscordPreview],
    ['slack', SlackPreview],
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

it('Mastodon nests each reply under the previous one (a true linked-list chain)', () => {
    const w = mount(MastodonPreview, {
        props: {
            channelName: 'My Mastodon',
            channelPicture: null,
            segments: [{ content: 'root toot' }, { content: 'reply one' }, { content: 'reply two' }],
        },
    });

    expect(w.text()).toContain('root toot');
    expect(w.text()).toContain('reply one');
    expect(w.text()).toContain('reply two');
    // Two "replying to the previous post" labels — one per non-root segment.
    expect(w.findAll('p').filter((p) => p.text().includes('Replying to the previous post'))).toHaveLength(2);
});

it('Slack renders every reply as a direct child of the ROOT, not chained reply-to-reply', () => {
    const w = mount(SlackPreview, {
        props: {
            channelName: 'My Slack',
            channelPicture: null,
            segments: [{ content: 'root message' }, { content: 'reply one' }, { content: 'reply two' }],
        },
    });

    // A single flat thread block holding both replies, not two nested rails.
    expect(w.text()).toContain('root message');
    expect(w.text()).toContain('2 replies in thread');
    expect(w.text()).toContain('reply one');
    expect(w.text()).toContain('reply two');
});

// Final review, Minor: the count was passed as a named argument only, so
// a thread with one reply read "1 replies in thread".
it('Slack counts a single reply in the singular', () => {
    const w = mount(SlackPreview, {
        props: {
            channelName: 'My Slack',
            channelPicture: null,
            segments: [{ content: 'root message' }, { content: 'the only reply' }],
        },
    });

    expect(w.text()).toContain('1 reply in thread');
    expect(w.text()).not.toContain('1 replies');
});

it('Discord renders extra segments as separate, explicitly unlinked messages', () => {
    const w = mount(DiscordPreview, {
        props: {
            channelName: 'My Discord',
            channelPicture: null,
            segments: [{ content: 'first' }, { content: 'second' }],
        },
    });

    expect(w.text()).toContain('first');
    expect(w.text()).toContain('second');
    expect(w.text()).toMatch(/does not support threading/i);
});

// --- PreviewPane resolves via the registry, never by branching ---------

it('PreviewPane renders TelegramPreview for a telegram identifier', () => {
    const w = mount(PreviewPane, {
        props: { providerIdentifier: 'telegram', channelName: 'C', channelPicture: null, segments: [{ content: 'hi' }] },
    });

    expect(w.findComponent(TelegramPreview).exists()).toBe(true);
});

it('PreviewPane falls back to GenericPreview for an unknown identifier', () => {
    const w = mount(PreviewPane, {
        props: { providerIdentifier: 'mystery-network', channelName: 'C', channelPicture: null, segments: [{ content: 'hi' }] },
    });

    expect(w.findComponent(GenericPreview).exists()).toBe(true);
});
