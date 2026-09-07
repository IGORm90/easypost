import type { Component } from 'vue';
import TelegramPreview from './TelegramPreview.vue';
import BlueskyPreview from './BlueskyPreview.vue';
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
 * Adding a network: drop in `ItsPreview.vue`, add one entry here.
 * Nothing outside this file (or `components/previews/` generally) needs
 * to change. Removing one is the same edit in reverse — a channel whose
 * network is gone keeps rendering, through `GenericPreview`.
 */
export const previewRegistry: Record<string, Component> = {
    telegram: TelegramPreview,
    bluesky: BlueskyPreview,
};

/** Resolves `identifier` to its preview component, defaulting to `GenericPreview` for anything not in the registry. */
export function resolvePreview(identifier: string): Component {
    return previewRegistry[identifier] ?? GenericPreview;
}
