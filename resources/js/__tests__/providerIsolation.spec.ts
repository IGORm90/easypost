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
/**
 * Final review, Important 10: this used to require the identifier to be
 * QUOTED, which made the guard blind to exactly the shape the violation
 * took — a bare object key. ConnectProviderModal.vue carried
 * `OAUTH_PARAM_FIELDS = { mastodon: [...] }` and
 * `MANUAL_FIELD_DEFS = { telegram: [...], bluesky: [...] }`: a hardcoded
 * per-provider behaviour map, living outside components/previews/, and
 * this guard called it green. So the quoted form is no longer the only
 * one checked: an identifier in KEY position (`mastodon: [...]`, whether
 * quoted or not) is the same hardcoded per-provider map by another
 * syntax, and is now caught too.
 *
 * Deliberately not a bare `\btelegram\b`: prose naming a provider as an
 * example of a generic mechanism ("whatever `extra` the backend returned
 * — Mastodon's `{ instance }` — is round-tripped untouched") is
 * documentation of provider-agnostic code, not a branch on a provider,
 * and banning it would only get the explanation deleted rather than the
 * coupling removed.
 */
const NAMES = PROVIDERS.join('|');
const PROVIDER_PATTERN = new RegExp(`['"\`](?:${NAMES})['"\`]|(?:^|[{,[(\\s])(?:${NAMES})\\s*:`, 'im');

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
