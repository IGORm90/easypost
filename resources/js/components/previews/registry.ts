import type { Component } from 'vue';
import TelegramPreview from './TelegramPreview.vue';
import BlueskyPreview from './BlueskyPreview.vue';
import GenericPreview from './GenericPreview.vue';
import TelegramIcon from './icons/TelegramIcon.vue';
import BlueskyIcon from './icons/BlueskyIcon.vue';
import GenericIcon from './icons/GenericIcon.vue';

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
 * Two maps live here now — which component draws a network's preview,
 * and which one draws its brand mark. The second one is here for the
 * same reason as the first and not because an icon is a preview: the
 * guard rejects a provider name in a file PATH too, so a
 * `TelegramIcon.vue` has nowhere else it could legally sit.
 *
 * Adding a network: drop in `ItsPreview.vue` and `ItsIcon.vue`, add one
 * entry to each map here (the icon entry only once a real mark exists —
 * `GenericIcon` is a correct answer, an empty box is not). Nothing
 * outside this file (or `components/previews/` generally) needs to
 * change. Removing one is the same edit in reverse — a channel whose
 * network is gone keeps rendering, through the two generic fallbacks.
 */
export const previewRegistry: Record<string, Component> = {
    telegram: TelegramPreview,
    bluesky: BlueskyPreview,
};

/** Resolves `identifier` to its preview component, defaulting to `GenericPreview` for anything not in the registry. */
export function resolvePreview(identifier: string): Component {
    return previewRegistry[identifier] ?? GenericPreview;
}

/**
 * A network's brand mark, keyed the same way `previewRegistry` is. Only
 * networks with a real logo appear here; everything else resolves to
 * `GenericIcon`, which is why the available-networks grid can list a
 * provider the moment its class ships, without waiting on artwork.
 *
 * The marks carry brand colour — the one exception color.md §6 grants to
 * the palette — which is another reason this map is not something a page
 * may inline: the exception is confined to `previews/icons/`.
 */
export const iconRegistry: Record<string, Component> = {
    telegram: TelegramIcon,
    bluesky: BlueskyIcon,
};

/** Resolves `identifier` to its brand mark, defaulting to `GenericIcon` for anything not in the registry. */
export function resolveProviderIcon(identifier: string): Component {
    return iconRegistry[identifier] ?? GenericIcon;
}
