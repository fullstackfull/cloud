import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * §29. Every `var(--…)` resolves, in every theme.
 *
 * A custom property that was never defined does not fail, warn, or appear
 * anywhere. It resolves to nothing and the element renders with whatever it
 * inherited. That is how `--surface-base` was referenced by thirty components
 * for months while every one of them rendered transparent, and how
 * `--danger-text` left the most important sentence in the portal — "existing
 * data may be permanently lost" — in the same colour as the line above it.
 *
 * Both were found by reading. This finds them by running.
 *
 * The second test is the same failure one level down. A token defined in
 * `:root` but not in a dark block keeps its light value on a dark ground,
 * which is how a tinted surface ends up with light-theme text on it. The
 * viewer has three states, not two — an explicit choice stamps `data-theme`,
 * and the default "system" setting stamps nothing — so a theme-dependent token
 * must be defined in all three places, never in one or two. This caught
 * `--danger-surface` and `--danger-surface-strong`, which Wave 5 added to
 * `:root` and to the media query and not to the stamp: a customer who had
 * chosen dark explicitly would have read every destructive button in the
 * light theme's red.
 */

const STYLES = path.resolve(import.meta.dirname, '..')
const SOURCE = path.resolve(import.meta.dirname, '../..')

const CSS = readFileSync(path.join(STYLES, 'index.css'), 'utf8')

describe('the design tokens', () => {
  it('defines every custom property something reads', () => {
    const defined = new Set(
      [...CSS.matchAll(/^\s*(--[a-z0-9-]+)\s*:/gm)].map((match) => match[1] ?? ''),
    )

    const used = new Map<string, string>()

    for (const file of sources()) {
      for (const match of readFileSync(file, 'utf8').matchAll(/var\((--[a-z0-9-]+)/g)) {
        used.set(match[1] ?? '', path.relative(SOURCE, file))
      }
    }

    const missing = [...used.entries()]
      .filter(([token]) => ! defined.has(token))
      .map(([token, where]) => `${token} (${where})`)

    expect(
      missing,
      'These resolve to nothing and inherit silently — no warning, no error, just the wrong colour.',
    ).toEqual([])
  })

  it('defines every theme-dependent token in all three theme states', () => {
    const light = tokensIn(/^:root \{$/m)
    const systemDark = tokensIn(/^ {2}:root:not\(\[data-theme='light'\]\) \{$/m)
    const chosenDark = tokensIn(/^:root\[data-theme='dark'\] \{$/m)

    // A sanity floor: if the block parser silently matched nothing, the three
    // comparisons below would all pass while checking nothing at all.
    expect(light.size).toBeGreaterThan(15)
    expect(systemDark.size).toBeGreaterThan(15)

    expect(
      [...systemDark].filter((token) => ! chosenDark.has(token)).sort(),
      'Redefined for a dark OS but not for somebody who chose dark in the app.',
    ).toEqual([])

    expect(
      [...chosenDark].filter((token) => ! systemDark.has(token)).sort(),
      'Redefined for a chosen dark theme but not for the default "system" setting, which stamps nothing.',
    ).toEqual([])

    expect(
      [...systemDark].filter((token) => ! light.has(token)).sort(),
      'Defined only in the dark: renders as nothing for everybody in light.',
    ).toEqual([])
  })
})

describe('motion', () => {
  it('is turned off for anybody who asked for less of it', () => {
    /*
     * W5.6. One block, at the root, rather than a `motion-reduce:` variant
     * remembered on each animated element — and it is asserted because the
     * failure is invisible to everybody who is not affected by it. The
     * spinner, the switch thumb and the skip link's slide are all animation
     * or transition, and somebody whose vestibular system objects to movement
     * has said so in their operating system already.
     *
     * `animation-iteration-count: 1` matters as much as the duration: a
     * spinner with a near-zero duration and an infinite count is still
     * animating, forever.
     */
    const block = /@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{([\s\S]*?)\n\}/.exec(CSS)

    expect(block, 'a reduced-motion block exists').not.toBeNull()

    const body = block?.[1] ?? ''

    expect(body).toMatch(/animation-duration:\s*0\.01ms\s*!important/)
    expect(body).toMatch(/animation-iteration-count:\s*1\s*!important/)
    expect(body).toMatch(/transition-duration:\s*0\.01ms\s*!important/)

    // Applied to everything, including generated content: a `::before` that
    // spins is still spinning.
    expect(body).toContain('*::before')
    expect(body).toContain('*::after')
  })
})


/**
 * The token names declared directly in the block whose header matches.
 *
 * Brace-counted rather than regex-matched to the closing brace, because the
 * dark block sits inside a media query and a non-greedy `.*?}` stops at the
 * wrong one — quietly, returning a short list that every comparison passes.
 */
function tokensIn(header: RegExp): Set<string> {
  const start = CSS.search(header)

  if (start === -1) throw new Error(`No block matching ${String(header)}`)

  let depth = 0
  let end = CSS.length

  for (let index = CSS.indexOf('{', start); index < CSS.length; index += 1) {
    if (CSS[index] === '{') depth += 1

    if (CSS[index] === '}') {
      depth -= 1

      if (depth === 0) {
        end = index
        break
      }
    }
  }

  return new Set(
    [...CSS.slice(start, end).matchAll(/^\s*(--[a-z0-9-]+)\s*:/gm)].map((match) => match[1] ?? ''),
  )
}

function sources(): string[] {
  const files: string[] = [path.join(STYLES, 'index.css')]

  walk(SOURCE, files)

  return files
}

function walk(directory: string, into: string[]): void {
  for (const entry of readdirSync(directory)) {
    if (entry === 'node_modules') continue

    const full = path.join(directory, entry)

    if (statSync(full).isDirectory()) {
      walk(full, into)
    } else if (full.endsWith('.tsx') || full.endsWith('.ts') || full.endsWith('.css')) {
      into.push(full)
    }
  }
}
