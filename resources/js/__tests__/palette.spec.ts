import { readFileSync } from 'node:fs'
import { globSync } from 'glob'

/**
 * The colour guard. Final review, Important 9 widened it three ways,
 * each of which was hiding something:
 *
 *  - the glob covered `**\/*.vue` only, so `.ts` files and — the one that
 *    mattered — every Blade view were unchecked. `resources/views/mail/
 *    weekly-digest.blade.php` hardcoded five colours (#1f2933, #f4f5f7,
 *    #6b7280, #e5e7eb, #9ca3af), none of which exist in color.md at all;
 *  - the banned-class list omitted eight Tailwind hues and every
 *    `from-`/`to-`/`via-`/`fill-`/`stroke-`/`divide-` prefix;
 *  - `rgb()`/`hsl()` literals were not looked for at all.
 *
 * Hex is not banned outright, because an HTML email cannot resolve a CSS
 * custom property — there is no stylesheet to resolve it against, so the
 * digest legitimately needs literal values. What is banned is a literal
 * that is not in the palette: the allow-list below is read out of
 * color.md itself, so it can never drift from the document it enforces.
 */
const PALETTE_DOC = 'color.md'

/** Every hex literal color.md defines, upper-cased and expanded to 6 digits. */
const allowedHex = new Set(
  (readFileSync(PALETTE_DOC, 'utf8').match(/#[0-9a-fA-F]{3,8}\b/g) ?? []).map(normaliseHex),
)

function normaliseHex(hex: string): string {
  const body = hex.slice(1).toUpperCase()

  return body.length === 3
    ? `#${body[0]}${body[0]}${body[1]}${body[1]}${body[2]}${body[2]}`
    : `#${body}`
}

const sources = [
  ...globSync('resources/js/**/*.{vue,ts}'),
  ...globSync('resources/views/**/*.blade.php'),
].filter((file) => !file.endsWith('.spec.ts'))

it('uses no colour literal that is not in the palette', () => {
  const offenders = sources
    .map((file) => {
      const offending = (readFileSync(file, 'utf8').match(/#[0-9a-fA-F]{3,8}\b/g) ?? []).filter(
        (hex) => !allowedHex.has(normaliseHex(hex)),
      )

      return { file, offending: [...new Set(offending)] }
    })
    .filter((entry) => entry.offending.length > 0)

  expect(offenders).toEqual([])
})

it('uses no rgb()/hsl() colour literals', () => {
  // color.md is expressed entirely in hex, so any functional colour
  // notation is by definition off-palette — including the ones that
  // would otherwise sneak a Tailwind default past the check above.
  const functional = /\b(?:rgba?|hsla?)\s*\(/
  const offenders = sources.filter((file) => functional.test(readFileSync(file, 'utf8')))

  expect(offenders).toEqual([])
})

it('has no default tailwind palette classes', () => {
  // Shaded palette classes (bg-gray-100, text-red-600, ...) *and* the
  // shade-less defaults (text-white, bg-black, border-transparent, ...),
  // which have no trailing -\d{2,3} and would otherwise slip past the
  // first alternative undetected. Every colour-bearing utility prefix is
  // listed, not just the four that happened to be in use when this guard
  // was written.
  const prefixes = 'bg|text|border|ring|outline|decoration|accent|caret|shadow|from|via|to|fill|stroke|divide|placeholder'
  const hues =
    'gray|slate|zinc|neutral|stone|emerald|teal|green|red|rose|pink|purple|violet|fuchsia|indigo|blue|sky|cyan|lime|amber|yellow|orange'
  const banned = new RegExp(`\\b(?:${prefixes})-(?:(?:${hues})-\\d{2,3}|white|black|transparent)\\b`)

  const offenders = sources.filter((file) => banned.test(readFileSync(file, 'utf8')))

  expect(offenders).toEqual([])
})
