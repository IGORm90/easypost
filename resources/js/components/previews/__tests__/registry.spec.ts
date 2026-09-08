import { iconRegistry, previewRegistry, resolvePreview, resolveProviderIcon } from '../registry';
import TelegramPreview from '../TelegramPreview.vue';
import BlueskyPreview from '../BlueskyPreview.vue';
import RedditPreview from '../RedditPreview.vue';
import GenericPreview from '../GenericPreview.vue';
import TelegramIcon from '../icons/TelegramIcon.vue';
import BlueskyIcon from '../icons/BlueskyIcon.vue';
import RedditIcon from '../icons/RedditIcon.vue';
import GenericIcon from '../icons/GenericIcon.vue';

/**
 * The registry is the ONLY place allowed to know that "telegram" maps to
 * a specific component — see components/previews/registry.ts's docblock
 * and the task-26 brief. Every component above the preview (PreviewPane,
 * PostEditorModal, ...) must go through `resolvePreview()` rather than
 * branching on a provider's identifier itself.
 */
it('exposes every known provider identifier in the registry map', () => {
    expect(previewRegistry).toEqual({
        telegram: TelegramPreview,
        bluesky: BlueskyPreview,
        reddit: RedditPreview,
    });
});

it('resolvePreview("telegram") returns TelegramPreview', () => {
    expect(resolvePreview('telegram')).toBe(TelegramPreview);
});

it('resolvePreview("bluesky") returns BlueskyPreview', () => {
    expect(resolvePreview('bluesky')).toBe(BlueskyPreview);
});

it('resolvePreview("reddit") returns RedditPreview', () => {
    expect(resolvePreview('reddit')).toBe(RedditPreview);
});

it('resolvePreview() defaults to GenericPreview for an unknown identifier', () => {
    expect(resolvePreview('some-future-network')).toBe(GenericPreview);
});

it('resolvePreview() falls back to GenericPreview for a network this build no longer ships', () => {
    // Channels connected before a network was removed still exist in the
    // database and still have to render — the fallback is what makes the
    // calendar and the editor survive them instead of blowing up on a
    // missing component.
    expect(resolvePreview('mastodon')).toBe(GenericPreview);
});

it('resolvePreview() defaults to GenericPreview for an empty identifier', () => {
    expect(resolvePreview('')).toBe(GenericPreview);
});

/**
 * The second map this file owns: a provider's brand mark. It lives here
 * for exactly the reason the preview map does — providerIsolation.spec.ts
 * forbids a provider identifier (in source OR in a file path) anywhere
 * under resources/js outside components/previews/, so an
 * `{ telegram: TelegramIcon }` map and a `TelegramIcon.vue` have nowhere
 * else they could legally live.
 */
it('exposes every provider that has a brand mark of its own', () => {
    expect(iconRegistry).toEqual({
        telegram: TelegramIcon,
        bluesky: BlueskyIcon,
        reddit: RedditIcon,
    });
});

it('resolveProviderIcon("telegram") returns the Telegram mark', () => {
    expect(resolveProviderIcon('telegram')).toBe(TelegramIcon);
});

it('resolveProviderIcon("bluesky") returns the Bluesky mark', () => {
    expect(resolveProviderIcon('bluesky')).toBe(BlueskyIcon);
});

it('resolveProviderIcon("reddit") returns the Reddit mark', () => {
    expect(resolveProviderIcon('reddit')).toBe(RedditIcon);
});

it('resolveProviderIcon() defaults to GenericIcon for a network with no mark yet', () => {
    // Every one of Block 5's eight planned networks lands here until its
    // own mark is drawn — the available-networks grid must render it as a
    // connectable card regardless, not as a hole.
    expect(resolveProviderIcon('some-future-network')).toBe(GenericIcon);
});

it('resolveProviderIcon() falls back to GenericIcon for a network this build no longer ships', () => {
    // Same reasoning as resolvePreview()'s fallback: a channel connected
    // before its network was removed still exists and still has to draw.
    expect(resolveProviderIcon('mastodon')).toBe(GenericIcon);
});

it('resolveProviderIcon() defaults to GenericIcon for an empty identifier', () => {
    expect(resolveProviderIcon('')).toBe(GenericIcon);
});
