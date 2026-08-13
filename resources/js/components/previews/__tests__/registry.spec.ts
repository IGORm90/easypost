import { previewRegistry, resolvePreview } from '../registry';
import TelegramPreview from '../TelegramPreview.vue';
import MastodonPreview from '../MastodonPreview.vue';
import BlueskyPreview from '../BlueskyPreview.vue';
import DiscordPreview from '../DiscordPreview.vue';
import SlackPreview from '../SlackPreview.vue';
import GenericPreview from '../GenericPreview.vue';

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
        mastodon: MastodonPreview,
        bluesky: BlueskyPreview,
        discord: DiscordPreview,
        slack: SlackPreview,
    });
});

it('resolvePreview("telegram") returns TelegramPreview', () => {
    expect(resolvePreview('telegram')).toBe(TelegramPreview);
});

it('resolvePreview("mastodon") returns MastodonPreview', () => {
    expect(resolvePreview('mastodon')).toBe(MastodonPreview);
});

it('resolvePreview("bluesky") returns BlueskyPreview', () => {
    expect(resolvePreview('bluesky')).toBe(BlueskyPreview);
});

it('resolvePreview("discord") returns DiscordPreview', () => {
    expect(resolvePreview('discord')).toBe(DiscordPreview);
});

it('resolvePreview("slack") returns SlackPreview', () => {
    expect(resolvePreview('slack')).toBe(SlackPreview);
});

it('resolvePreview() defaults to GenericPreview for an unknown identifier', () => {
    expect(resolvePreview('some-future-network')).toBe(GenericPreview);
});

it('resolvePreview() defaults to GenericPreview for an empty identifier', () => {
    expect(resolvePreview('')).toBe(GenericPreview);
});
