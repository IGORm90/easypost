import { previewRegistry, resolvePreview } from '../registry';
import TelegramPreview from '../TelegramPreview.vue';
import BlueskyPreview from '../BlueskyPreview.vue';
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
        bluesky: BlueskyPreview,
    });
});

it('resolvePreview("telegram") returns TelegramPreview', () => {
    expect(resolvePreview('telegram')).toBe(TelegramPreview);
});

it('resolvePreview("bluesky") returns BlueskyPreview', () => {
    expect(resolvePreview('bluesky')).toBe(BlueskyPreview);
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
