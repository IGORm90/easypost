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
/**
 * Block 5's eight networks are listed here from the moment each provider
 * exists. Two of them do not carry the network's plain name, and the
 * reason lives in this very file: PROVIDER_FILENAME_PATTERN matches an
 * identifier as a bare substring of a FILE PATH, with no word boundary
 * and no case sensitivity.
 *
 *  - X is 'twitter'. With 'x' in this list, `PreviewText.vue`,
 *    `ContentTextarea.vue` and every other path containing the letter
 *    would be reported as a provider-named module, and `['"`]x['"`]`
 *    would fire on any single-character string in the codebase.
 *  - Threads is 'meta-threads'. Its backend counterpart explains why
 *    ('threads_not_supported' is generic capability vocabulary); the
 *    two lists are kept in step so a violation cannot be green on one
 *    side and red on the other.
 *
 * Consequence worth knowing before adding a file: no source file outside
 * components/previews/ may have any of these names in its PATH. That
 * rules out, say, `components/editor/InstagramCarousel.vue` — which is
 * the point, since such a file is per-provider behaviour by definition.
 */
const PROVIDERS = [
    'telegram',
    'mastodon',
    'bluesky',
    'discord',
    'slack',
    'twitter',
    'linkedin',
    'reddit',
    'facebook-page',
    'instagram',
    'meta-threads',
];
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
/**
 * Final review round 2 found two more syntaxes the alternation above
 * still could not see, both of which need no quote adjacent to the name:
 *
 *  - property assignment / member access. `FIELDS.telegram = ['bot_token']`
 *    builds the identical per-provider map the object-literal form does,
 *    one statement later; `config.mastodon.instance` reads one. Neither
 *    puts a quote or a `:` anywhere near the name.
 *  - a module named after a provider. `import X from './TelegramFields.vue'`
 *    is per-provider behaviour by filename — the name lives in the path
 *    rather than the source, so no content pattern can reach it. Both the
 *    importing statement and the file's own name are checked, because the
 *    file existing outside components/previews/ is the coupling whether
 *    or not anything imports it yet.
 */
const NAMES = PROVIDERS.join('|');
const PROVIDER_PATTERN = new RegExp(
    `['"\`](?:${NAMES})['"\`]|(?:^|[{,[(\\s])(?:${NAMES})\\s*:|\\.(?:${NAMES})\\b`,
    'im',
);
/** `from './TelegramFields.vue'`, `import('../x/telegram-fields')`, `require('…')`. */
const PROVIDER_IMPORT_PATTERN = new RegExp(
    `(?:from|import|require)\\s*\\(?\\s*['"\`][^'"\`]*(?:${NAMES})[^'"\`]*['"\`]`,
    'im',
);
const PROVIDER_FILENAME_PATTERN = new RegExp(`(?:${NAMES})`, 'i');

// components/previews/ is where these identifiers are meant to live
// (the registry map, and each Preview*.vue's own doc comments/labels).
// Spec files are excluded too: a test exercising resolvePreview() with
// literal identifiers is fixture data, not the kind of branching this
// guard exists to catch — same reasoning the backend guard applies by
// only scanning app/, never tests/.
const ALLOWED_DIR = 'resources/js/components/previews/';

const sources = globSync('resources/js/**/*.{vue,ts}').filter(
    (file) => !file.startsWith(ALLOWED_DIR) && !file.endsWith('.spec.ts'),
);

it('has no provider-specific identifiers outside components/previews/', () => {
    const offenders = sources.filter((file) => PROVIDER_PATTERN.test(readFileSync(file, 'utf8')));

    expect(offenders).toEqual([]);
});

it('imports no provider-named module outside components/previews/', () => {
    // Reaching a provider's preview directly is the coupling
    // resolvePreview() exists to prevent, and it leaves no provider name
    // in the source outside the import path.
    const offenders = sources.filter((file) => PROVIDER_IMPORT_PATTERN.test(readFileSync(file, 'utf8')));

    expect(offenders).toEqual([]);
});

it('has no provider-named file outside components/previews/', () => {
    const offenders = sources.filter((file) => PROVIDER_FILENAME_PATTERN.test(file));

    expect(offenders).toEqual([]);
});

/**
 * Same reasoning as palette.spec.ts's `the guard itself` block: this
 * guard has now twice been found blind to the very syntax the violation
 * used, and a pattern that matches nothing is indistinguishable from a
 * codebase that is clean. Fixed samples, both directions.
 */
describe('the guard itself', () => {
    it.each([
        ['a quoted identifier', `if (provider === 'telegram') {`],
        ['a bare object key', 'const MANUAL_FIELD_DEFS = { telegram: [], bluesky: [] }'],
        ['a quoted object key', `const X = { 'mastodon': [] }`],
        ['a property assignment', `FIELDS.telegram = ['bot_token']`],
        ['a member read', 'const instance = config.mastodon.instance'],
    ])('catches %s', (_label, sample) => {
        expect(PROVIDER_PATTERN.test(sample)).toBe(true);
    });

    it.each([
        ['a generic identifier field', 'const id = channel.provider_identifier'],
        ['prose naming a provider as an example', "// whatever `extra` the backend returned — Mastodon's `{ instance }` — is round-tripped"],
    ])('does not fire on %s', (_label, sample) => {
        expect(PROVIDER_PATTERN.test(sample)).toBe(false);
    });

    it.each([
        ['a relative import', `import X from './TelegramFields.vue'`],
        ['a dynamic import', `const m = await import('../previews/PreviewMastodon.vue')`],
        ['a kebab-case path', `import X from '@/widgets/bluesky-fields'`],
    ])('catches %s', (_label, sample) => {
        expect(PROVIDER_IMPORT_PATTERN.test(sample)).toBe(true);
    });

    it('does not fire on a provider-agnostic import', () => {
        expect(PROVIDER_IMPORT_PATTERN.test(`import { resolvePreview } from '@/components/previews/registry'`)).toBe(false);
    });

    it('scans a non-empty set of sources', () => {
        // If the glob or the exclusion ever stops matching, all three
        // checks above pass vacuously and say nothing.
        expect(sources.length).toBeGreaterThan(10);
    });
});
