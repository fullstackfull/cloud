import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * §19, §6. The two rules that decide whether a wide thing scrolls its own box
 * or drags the whole page sideways.
 *
 * Both were found by measuring rather than reading, and both are invisible in
 * a screenshot, which is why they are asserted here rather than left to a
 * reviewer's eye.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')
const CSS = readFileSync(path.join(SOURCE, 'styles/index.css'), 'utf8')

/**
 * A scroll container that is `position: static` is nobody's containing block,
 * and an absolutely positioned box is clipped only by an ancestor that *is*
 * its containing block. So `sr-only` text — which is `position: absolute` —
 * inside a static `overflow-x-auto` scroller is not clipped by that scroller
 * at all: it escapes into the page's scrollable overflow and the document
 * scrolls sideways.
 *
 * That is not a hypothetical. Measured at 360px, nine customer screens
 * scrolled the page sideways because of the off-screen "Actions" heading in
 * the last column of a table, and the amount each one scrolled by was exactly
 * the position of that heading. Nothing was painted out there — `sr-only` also
 * sets `clip: rect(0, 0, 0, 0)` — so every screenshot of every one of those
 * screens looked correct.
 *
 * The rule, therefore: a horizontal scroll container is also a containing
 * block. Stated as "carries a position" rather than "carries `relative`",
 * because `sticky` and `absolute` would satisfy it equally.
 */
const POSITIONED = /\b(relative|absolute|fixed|sticky)\b/

describe('a container that scrolls sideways', () => {
  it('is also a containing block, so that what it clips includes what is positioned', () => {
    const unheld: string[] = []

    for (const file of sources()) {
      const source = readFileSync(file, 'utf8')

      for (const classes of classAttributes(source)) {
        if (! /\boverflow-x-(auto|scroll)\b/.test(classes)) continue
        if (POSITIONED.test(classes)) continue

        unheld.push(`${path.relative(SOURCE, file)}: "${classes.slice(0, 90)}"`)
      }
    }

    expect(
      unheld,
      'A static scroller does not clip an absolutely positioned descendant — sr-only text ' +
        'escapes it and the whole page scrolls sideways, with nothing visible to show why.',
    ).toEqual([])
  })

  it('is one of a known few, so that a new one is a decision rather than a habit', () => {
    const scrollers: string[] = []

    for (const file of sources()) {
      for (const classes of classAttributes(readFileSync(file, 'utf8'))) {
        if (/\boverflow-x-(auto|scroll)\b/.test(classes)) scrollers.push(path.relative(SOURCE, file))
      }
    }

    /*
     * Three, and each one is the answer to a question §5 asks: a table wider
     * than a phone, a permission matrix wider than a phone, and a row of
     * section links longer than a phone. Horizontal scrolling *inside* a
     * deliberate container is allowed; the page scrolling is not. A fourth
     * entry here means somebody reached for a scroller instead of making
     * something narrow, and should have to say so.
     */
    expect(scrollers.sort()).toEqual([
      'components/DataTable.tsx',
      'components/ResourceTabs.tsx',
      'features/team/RolePermissionMatrix.tsx',
    ])
  })
})

describe('a technical value', () => {
  it('wraps instead of pushing, and does it from the class rather than the call site', () => {
    const technical = /\.technical\s*\{([^}]*)\}/.exec(CSS)?.[1] ?? ''

    expect(technical, '.technical is not defined in index.css').not.toBe('')

    /*
     * `anywhere` and not `break-word`. Both break a word that would otherwise
     * overflow; only `anywhere` also lowers the box's min-content width, which
     * is the part that lets a flex item, a grid track or a table cell shrink
     * around a 48-character token instead of refusing to.
     */
    expect(
      technical,
      'A technical value is one unbreakable word — a token, an IPv6 address, a ULID, a long ' +
        'domain. Without this it extends past whatever holds it.',
    ).toMatch(/overflow-wrap:\s*anywhere/)
  })

  it('is used far more often than it is given a wrapping rule at the call site', () => {
    /*
     * The reason the rule lives in the class. This is not a style preference:
     * of the places that render a technical value, the large majority say
     * nothing at all about wrapping, so a per-site fix would have been a
     * hundred-odd edits with a hundred chances to miss one — and the one
     * missed is a page that scrolls.
     *
     * Asserted as a ratio rather than a count so that it does not fail every
     * time somebody adds a row to a table.
     */
    let sites = 0
    let withOwnRule = 0

    for (const file of sources()) {
      for (const line of readFileSync(file, 'utf8').split('\n')) {
        if (! line.includes('technical')) continue
        sites += 1
        if (/break-all|break-words|wrap-anywhere|truncate/.test(line)) withOwnRule += 1
      }
    }

    expect(sites).toBeGreaterThan(50)
    expect(
      withOwnRule / sites,
      'If most call sites carried their own wrapping rule, the class-level rule would be ' +
        'redundant and this reasoning would need revisiting.',
    ).toBeLessThan(0.5)
  })
})

/** Every `className` string in the file, including the multi-line ones. */
function classAttributes(source: string): string[] {
  const found: string[] = []

  for (const match of source.matchAll(/className=(?:"([^"]*)"|\{`([^`]*)`\}|\{\[([^\]]*)\])/g)) {
    found.push((match[1] ?? match[2] ?? match[3] ?? '').replace(/\s+/g, ' ').trim())
  }

  // `cn('a b', condition ? 'c' : 'd')` and the class maps in buttonStyles.
  for (const match of source.matchAll(/cn\(([\s\S]{0,400}?)\)/g)) {
    found.push((match[1] ?? '').replace(/\s+/g, ' ').trim())
  }

  return found
}

function sources(): string[] {
  const found: string[] = []

  const walk = (directory: string): void => {
    for (const entry of readdirSync(directory)) {
      const full = path.join(directory, entry)

      if (statSync(full).isDirectory()) {
        if (entry !== '__tests__') walk(full)
        continue
      }

      if (full.endsWith('.tsx')) found.push(full)
    }
  }

  walk(SOURCE)

  return found
}
