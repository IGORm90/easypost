import { readFileSync } from 'node:fs'
import { globSync } from 'glob'

const sources = globSync('resources/js/**/*.vue')

it('has no raw hex colors in components', () => {
  const offenders = sources.filter(f => /#[0-9a-fA-F]{3,8}\b/.test(readFileSync(f, 'utf8')))
  expect(offenders).toEqual([])
})

it('has no default tailwind palette classes in components', () => {
  const banned = /\b(?:bg|text|border|ring)-(?:gray|slate|zinc|neutral|stone|emerald|teal|green|red|amber|yellow|orange|blue|indigo)-\d{2,3}\b/
  const offenders = sources.filter(f => banned.test(readFileSync(f, 'utf8')))
  expect(offenders).toEqual([])
})
