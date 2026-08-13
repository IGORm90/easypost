import { readFileSync } from 'node:fs'
import { globSync } from 'glob'

const sources = globSync('resources/js/**/*.vue')

it('has no raw hex colors in components', () => {
  const offenders = sources.filter(f => /#[0-9a-fA-F]{3,8}\b/.test(readFileSync(f, 'utf8')))
  expect(offenders).toEqual([])
})

it('has no default tailwind palette classes in components', () => {
  // Shaded palette classes (bg-gray-100, text-red-600, ...) *and* the
  // shade-less defaults (text-white, bg-black, border-transparent, ...),
  // which have no trailing -\d{2,3} and would otherwise slip past the
  // first alternative undetected.
  const banned = /\b(?:bg|text|border|ring)-(?:(?:gray|slate|zinc|neutral|stone|emerald|teal|green|red|amber|yellow|orange|blue|indigo)-\d{2,3}|white|black|transparent)\b/
  const offenders = sources.filter(f => banned.test(readFileSync(f, 'utf8')))
  expect(offenders).toEqual([])
})
