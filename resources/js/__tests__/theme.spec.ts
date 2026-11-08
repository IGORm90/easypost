import { readFileSync } from 'node:fs'
import { globSync } from 'glob'
// @ts-expect-error -- tailwind.config.js is plain ESM with no declaration file; the shape is asserted below
import tailwindConfig from '../../../tailwind.config.js'

/**
 * The «Кобальт» theme guard. palette.spec.ts already stops a literal that
 * color.md does not list; this file pins the things that guard cannot see:
 *
 *  - that the two themes define the *same* set of tokens — a token added to
 *    :root and forgotten in .dark silently falls back to the light value
 *    in the dark theme, which is exactly how a near-white border ends up
 *    on a near-black page;
 *  - that every token Tailwind maps actually exists, so a typo in the
 *    config becomes a red test rather than an unstyled element;
 *  - that no class puts an opacity modifier on a token. Every token is
 *    mapped as a bare `var(--token)` with no <alpha-value>, and Tailwind 3
 *    drops `border-success/40` without a word — the Toast border that
 *    rendered as no border at all.
 */
const css = readFileSync('resources/css/app.css', 'utf8')

function block(selector: string): Map<string, string> {
  const start = css.indexOf(`${selector} {`)
  expect(start, `${selector} block missing from app.css`).toBeGreaterThanOrEqual(0)
  const body = css.slice(start, css.indexOf('\n}', start))
  const props = new Map<string, string>()

  for (const match of body.matchAll(/(--[a-z0-9-]+):\s*([^;]+);/g)) {
    props.set(match[1], match[2].trim().toUpperCase())
  }

  return props
}

const light = block(':root')
const dark = block('.dark')

type ColorValue = string | Record<string, string>
const colors = (tailwindConfig as { theme: { extend: { colors: Record<string, ColorValue>; fontFamily: Record<string, string[]> } } })
  .theme.extend.colors
const fonts = (tailwindConfig as { theme: { extend: { fontFamily: Record<string, string[]> } } }).theme.extend.fontFamily

function mappedVars(): string[] {
  return Object.values(colors).flatMap((value) => (typeof value === 'string' ? [value] : Object.values(value)))
    .map((value) => value.match(/var\((--[a-z0-9-]+)\)/)?.[1] ?? '')
}

it('defines the same tokens in the light and the dark theme', () => {
  expect([...dark.keys()].sort()).toEqual([...light.keys()].sort())
})

it('uses the Cobalt values from color.md for the anchors of the light theme', () => {
  expect(light.get('--bg')).toBe('#F4F6FA')
  expect(light.get('--text')).toBe('#0E1A33')
  expect(light.get('--primary')).toBe('#2453D6')
  expect(light.get('--nav')).toBe('#0B1B3F')
  expect(light.get('--border-strong')).toBe('#D5DCEA')
})

it('maps only tokens that exist, and maps the navigation and border-strong tokens', () => {
  for (const name of mappedVars()) {
    expect(light.has(name), `${name} is mapped in tailwind.config.js but not defined in app.css`).toBe(true)
  }

  expect(colors).toHaveProperty('nav')
  expect(colors).toHaveProperty('border-strong')
  expect(colors).toHaveProperty('on-nav')
})

it('sets IBM Plex Sans as the text face and IBM Plex Mono for figures', () => {
  expect(fonts.sans[0]).toBe('IBM Plex Sans')
  expect(fonts.mono[0]).toBe('IBM Plex Mono')
})

it('puts no opacity modifier on a token class, which Tailwind would drop silently', () => {
  const tokens = Object.keys(colors).join('|')
  const pattern = new RegExp(`\\b(?:bg|text|border|ring|outline|divide|fill|stroke|from|via|to|placeholder|accent)-(?:${tokens})(?:-[a-z]+)?/\\d+`, 'g')
  const offenders = globSync('resources/js/**/*.{vue,ts}')
    .filter((file) => !file.endsWith('.spec.ts'))
    .flatMap((file) => (readFileSync(file, 'utf8').match(pattern) ?? []).map((hit) => `${file}: ${hit}`))

  expect(offenders).toEqual([])
})
