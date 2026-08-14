import { readFileSync } from 'node:fs'
import { globSync } from 'glob'

/**
 * Final review, Important 11: four `<Input>` call sites rendered with no
 * accessible name at all. Three passed only a `placeholder` — which a
 * screen reader may read but is not a label, and which vanishes the
 * moment the user types — and the calendar's "Customer" field showed a
 * visible label that was associated with nothing.
 *
 * `Input.vue` now generates its own `id` so a passed `label` is always
 * associated. What it cannot invent is a name that was never given, so
 * this guard requires every call site to pass one of the two.
 */
const SOURCES = 'resources/js/**/*.vue'

/** Matches a single `<Input …>` tag, including multi-line ones. */
const INPUT_TAG = /<Input\b[^>]*>/g

/** Either is a valid accessible name; `aria-labelledby` points at one elsewhere. */
const NAMED = /(^|\s):?(label|aria-label|aria-labelledby)[=\s>]/

it('gives every <Input> an accessible name — a placeholder is not a label', () => {
  const offenders: string[] = []

  for (const file of globSync(SOURCES, { ignore: ['**/__tests__/**'] })) {
    // The component itself defines the prop rather than passing it.
    if (file.endsWith('components/ui/Input.vue')) {
      continue
    }

    // Comments may mention the tag by name without being a call site.
    const source = readFileSync(file, 'utf8').replace(/<!--[\s\S]*?-->/g, '')

    for (const tag of source.match(INPUT_TAG) ?? []) {
      if (!NAMED.test(tag)) {
        offenders.push(`${file}: ${tag.replace(/\s+/g, ' ')}`)
      }
    }
  }

  expect(offenders).toEqual([])
})
