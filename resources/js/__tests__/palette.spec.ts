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
  // Final review round 2: the stylesheet that *defines* the palette was
  // itself unchecked, so a literal added straight to app.css — the most
  // likely place for one — met no gate at all.
  ...globSync('resources/css/**/*.css'),
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

/**
 * The 148 CSS named colours. `transparent`, `currentColor` and the
 * global keywords (`inherit`, `initial`, `unset`, `revert`) are
 * deliberately absent: none of them names a colour value, so none can be
 * off-palette, and `transparent` in particular has no palette equivalent
 * to redirect an author to.
 */
const NAMED_COLOURS = [
  'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black',
  'blanchedalmond', 'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse',
  'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan',
  'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta',
  'darkolivegreen', 'darkorange', 'darkorchid', 'darkred', 'darksalmon', 'darkseagreen',
  'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink',
  'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen',
  'fuchsia', 'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green', 'greenyellow',
  'grey', 'honeydew', 'hotpink', 'indianred', 'indigo', 'ivory', 'khaki', 'lavender',
  'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan',
  'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink', 'lightsalmon',
  'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue',
  'lightyellow', 'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine',
  'mediumblue', 'mediumorchid', 'mediumpurple', 'mediumseagreen', 'mediumslateblue',
  'mediumspringgreen', 'mediumturquoise', 'mediumvioletred', 'midnightblue', 'mintcream',
  'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace', 'olive', 'olivedrab', 'orange',
  'orangered', 'orchid', 'palegoldenrod', 'palegreen', 'paleturquoise', 'palevioletred',
  'papayawhip', 'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple',
  'red', 'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown', 'seagreen', 'seashell',
  'sienna', 'silver', 'skyblue', 'slateblue', 'slategray', 'slategrey', 'snow', 'springgreen',
  'steelblue', 'tan', 'teal', 'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white',
  'whitesmoke', 'yellow', 'yellowgreen',
].join('|')

/**
 * The gap that let `--fc-now-indicator-color: red` through and produced
 * the `+N more` contrast bug. Every other check here looks for a
 * *notation* — hex, rgb(), a Tailwind class — and a named colour is none
 * of those, so `style="color: red"` met no gate at all.
 *
 * Two shapes are matched: a declaration (`color: red`, and any custom
 * property whose name ends in -color, which is how the FullCalendar
 * overrides are spelled) and a presentation attribute (`fill="red"` on
 * inline SVG, which is not CSS but paints the same pixels).
 */
const NAMED_COLOUR_DECLARATION = new RegExp(
  `(?:^|[\\s;{"'])(?:--[\\w-]*|-{0,2}[\\w-]*?)(?:color|background|fill|stroke)[\\w-]*\\s*:\\s*(?:${NAMED_COLOURS})\\b`,
  'i',
)
const NAMED_COLOUR_ATTRIBUTE = new RegExp(
  `\\b(?:fill|stroke|color|stop-color|bgcolor)\\s*=\\s*["'](?:${NAMED_COLOURS})["']`,
  'i',
)

it('uses no named CSS colour', () => {
  const offenders = sources.filter((file) => {
    const contents = readFileSync(file, 'utf8')

    return NAMED_COLOUR_DECLARATION.test(contents) || NAMED_COLOUR_ATTRIBUTE.test(contents)
  })

  expect(offenders).toEqual([])
})

it('has no default tailwind palette classes', () => {
  // Shaded palette classes (bg-gray-100, text-red-600, ...) *and* the
  // shade-less defaults (text-white, bg-black, border-transparent, ...),
  // which have no trailing -\d{2,3} and would otherwise slip past the
  // first alternative undetected. Every colour-bearing utility prefix is
  // listed, not just the four that happened to be in use when this guard
  // was written.
  // `ring-offset` is spelled out because `ring` alone does not cover it:
  // `ring-offset-white` reads as prefix `ring` followed by `offset-white`,
  // which matches no hue and no shade-less default.
  const prefixes =
    'bg|text|border|ring-offset|ring|outline|decoration|accent|caret|shadow|from|via|to|fill|stroke|divide|placeholder'
  const hues =
    'gray|slate|zinc|neutral|stone|emerald|teal|green|red|rose|pink|purple|violet|fuchsia|indigo|blue|sky|cyan|lime|amber|yellow|orange'
  const banned = new RegExp(`\\b(?:${prefixes})-(?:(?:${hues})-\\d{2,3}|white|black|transparent)\\b`)

  const offenders = sources.filter((file) => banned.test(readFileSync(file, 'utf8')))

  expect(offenders).toEqual([])
})

/**
 * A guard that matches nothing passes, and reads exactly like a guard
 * that found nothing. All three checks above were widened after review
 * found each of them blind to a whole class of value — so each one now
 * has to prove, against fixed samples, that it can still see.
 *
 * The clean samples matter as much as the dirty ones: `text-primary`,
 * `--surface-2`, and `colorScheme` are the shapes an over-eager pattern
 * would start rejecting, and a guard nobody can satisfy gets deleted.
 */
describe('the guard itself', () => {
  const bannedTailwind = new RegExp(
    `\\b(?:bg|text|border|ring-offset|ring|outline|decoration|accent|caret|shadow|from|via|to|fill|stroke|divide|placeholder)-(?:(?:gray|slate|zinc|neutral|stone|emerald|teal|green|red|rose|pink|purple|violet|fuchsia|indigo|blue|sky|cyan|lime|amber|yellow|orange)-\\d{2,3}|white|black|transparent)\\b`,
  )

  it.each([
    ['inline style', 'style="color: red"'],
    ['shorthand background', 'style="background: white"'],
    ['hyphenated property', 'style="border-color: grey"'],
    // The literal that produced the `+N more` contrast bug.
    ['custom property', '--fc-now-indicator-color: red;'],
    ['stylesheet declaration', '.badge { background-color: WhiteSmoke; }'],
  ])('catches a named colour in a %s', (_label, sample) => {
    expect(NAMED_COLOUR_DECLARATION.test(sample)).toBe(true)
  })

  it.each([
    ['svg fill', '<circle fill="red" />'],
    ['svg stroke', '<path stroke="black" />'],
  ])('catches a named colour in an %s', (_label, sample) => {
    expect(NAMED_COLOUR_ATTRIBUTE.test(sample)).toBe(true)
  })

  it.each([
    ['a token reference', 'color: var(--danger);'],
    ['a palette custom property', '--surface-2: #F7FAF8;'],
    ['a non-colour property', 'colorScheme: schemeFor(mode)'],
    ['a word that merely contains a colour name', 'background: url(/img/redesign.png)'],
  ])('does not fire on %s', (_label, sample) => {
    expect(NAMED_COLOUR_DECLARATION.test(sample)).toBe(false)
    expect(NAMED_COLOUR_ATTRIBUTE.test(sample)).toBe(false)
  })

  it.each([
    ['a shaded class', 'class="bg-gray-100"'],
    ['a shade-less default', 'class="text-white"'],
    // The class the review's scratch file used to walk straight through.
    ['a ring-offset class', 'class="ring-offset-white"'],
  ])('catches %s', (_label, sample) => {
    expect(bannedTailwind.test(sample)).toBe(true)
  })

  it.each([
    ['a palette token class', 'class="text-primary"'],
    ['a non-colour utility', 'class="ring-2 border-x"'],
  ])('does not fire on %s', (_label, sample) => {
    expect(bannedTailwind.test(sample)).toBe(false)
  })

  it('reads a non-empty allow-list out of the palette document', () => {
    // If color.md is ever moved or restructured, `allowedHex` silently
    // becomes empty and the first check starts rejecting the entire
    // palette — loud, but for the wrong reason. Say so here instead.
    expect(allowedHex.size).toBeGreaterThan(10)
  })

  it('scans every source family the app paints from', () => {
    for (const suffix of ['.vue', '.ts', '.blade.php', '.css']) {
      expect(sources.some((file) => file.endsWith(suffix))).toBe(true)
    }
  })
})
