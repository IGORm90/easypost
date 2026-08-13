import type { Component } from 'vue';
import TelegramPreview from './TelegramPreview.vue';
import MastodonPreview from './MastodonPreview.vue';
import BlueskyPreview from './BlueskyPreview.vue';
import DiscordPreview from './DiscordPreview.vue';
import SlackPreview from './SlackPreview.vue';
import GenericPreview from './GenericPreview.vue';

/**
 * The frontend mirror of the backend's `ProviderRegistry`
 * (app/Social/ProviderRegistry.php): the ONE place that maps a
 * provider's identifier string to concrete behaviour — here, which Vue
 * component draws its preview. Every component above a preview
 * (PreviewPane.vue, PostEditorModal.vue, ...) must go through
 * `resolvePreview()` and MUST NOT branch on a provider's identifier
 * itself (`v-if="provider === 'telegram'"` or similar) — see this
 * project's frontend isolation guard,
 * resources/js/__tests__/providerIsolation.spec.ts, which fails the
 * build the moment a provider name appears in any `.vue`/`.ts` file
 * outside this `components/previews/` directory. That guard is the
 * frontend analogue of tests/Unit/Architecture/ProviderIsolationTest.php.
 *
 * Adding a sixth network: drop in `SixthPreview.vue`, add one entry
 * here. Nothing outside this file (or `components/previews/` generally)
 * needs to change.
 */
export const previewRegistry: Record<string, Component> = {
    telegram: TelegramPreview,
    mastodon: MastodonPreview,
    bluesky: BlueskyPreview,
    discord: DiscordPreview,
    slack: SlackPreview,
};

/** Resolves `identifier` to its preview component, defaulting to `GenericPreview` for anything not in the registry. */
export function resolvePreview(identifier: string): Component {
    return previewRegistry[identifier] ?? GenericPreview;
}
