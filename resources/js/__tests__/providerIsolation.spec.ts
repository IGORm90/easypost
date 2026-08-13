import { readFileSync } from 'node:fs'
import { globSync } from 'glob'

/**
 * Frontend analogue of tests/Unit/Architecture/ProviderIsolationTest.php.
 * That backend test fails the build the moment a provider's name
 * ('telegram', 'mastodon', ...) shows up anywhere in app/ outside
 * app/Social/Providers — the ONE place allowed to know which provider is
 * which. Task 26 introduces the exact same shape of rule on the
 * frontend: resources/js/components/previews/registry.ts is the ONLY
 * place allowed to map a provider identifier to behaviour (which
 * preview component renders it); everything above a preview
 * (PreviewPane.vue, PostEditorModal.vue, ChannelTab.vue, ...) must reach
 * a provider's rendering exclusively through `resolvePreview()`.
 *
 * Nothing enforced this before Task 26 — there was nothing to enforce,
 * since provider identifiers previously never appeared in resources/js
 * at all (every prior task went through generic `identifier`/
 * `provider_identifier` fields, never a hardcoded name). This guard
 * exists so the very first legitimate use (the preview registry) does
 * not become a precedent for `v-if="provider === 'telegram'"` creeping
 * into a parent component later.
 */
const PROVIDERS = ['telegram', 'mastodon', 'bluesky', 'discord', 'slack'];
const PROVIDER_PATTERN = new RegExp(`['"\`](?:${PROVIDERS.join('|')})['"\`]`, 'i');

// components/previews/ is where these identifiers are meant to live
// (the registry map, and each Preview*.vue's own doc comments/labels).
// Spec files are excluded too: a test exercising resolvePreview() with
// literal identifiers is fixture data, not the kind of branching this
// guard exists to catch — same reasoning the backend guard applies by
// only scanning app/, never tests/.
const ALLOWED_DIR = 'resources/js/components/previews/';

it('has no provider-specific identifiers outside components/previews/', () => {
    const sources = globSync('resources/js/**/*.{vue,ts}').filter(
        (file) => !file.startsWith(ALLOWED_DIR) && !file.endsWith('.spec.ts'),
    );

    const offenders = sources.filter((file) => PROVIDER_PATTERN.test(readFileSync(file, 'utf8')));

    expect(offenders).toEqual([]);
});
