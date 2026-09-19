import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * §9. One definition of what a button looks like.
 *
 * `buttonStyles.ts` exists because three places need a control that navigates
 * rather than submits, and its own docblock says why a hand-copied class
 * string is the wrong answer: it is a second definition of "what a button
 * looks like", and it drifts on the first restyle.
 *
 * It had drifted. Seven links were drawn by hand rather than by
 * `buttonClasses`, in two recipes:
 *
 *  - Five copies of a small secondary control — `rounded` where the system
 *    says `rounded-lg`, `--border` where the system says `--border-strong`,
 *    `px-2 py-1 text-xs` (26px tall) where the system's small size is `h-8`
 *    (32px), no surface behind it, no `font-medium`, no `transition-colors`.
 *    Two of them sat directly beside real `<Button variant="secondary"
 *    size="sm">` controls, so one row held two heights, two radii and two
 *    border weights for the same kind of action.
 *
 *  - Two copies of a primary control, on the verification page, with **no
 *    hover and no active state at all** — a link painted like the most
 *    important button on the screen that did not respond to the pointer.
 *
 * Both are the kind of thing that is easy to see once it is pointed at and
 * almost impossible to notice while writing one screen at a time. So it is
 * asserted rather than reviewed.
 *
 * What this gate is and is not: it catches the two recipes that were actually
 * copied, not every conceivable hand-drawn control. A gate that tried to
 * recognise "looks like a button" in general would have to guess, and a
 * guessing gate is one somebody disables.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')
const SYSTEM = 'components/buttonStyles.ts'

/**
 * Where the brand fill is allowed to appear outside the button system, and
 * why. Neither is a control: one is a count, one is a selected state.
 */
const NOT_A_BUTTON: Record<string, string> = {
  'app/UnreadBadge.tsx':
    'The unread count. A `rounded-full` pill showing a number, not something anybody presses — ' +
    'the press target is the navigation link it sits inside.',
  'features/catalog/CataloguePage.tsx':
    'The selected product-kind filter. A chip whose filled state means "this is the filter you ' +
    'are looking through", which is a state and not an action.',
}

describe('a control in this portal', () => {
  it('takes the brand fill from the button system, or is not a control', () => {
    const offenders: string[] = []

    for (const file of sources()) {
      const relative = path.relative(SOURCE, file)
      if (relative === SYSTEM) continue

      const source = readFileSync(file, 'utf8')

      for (const line of source.split('\n')) {
        // `bg-brand-600/10` is a tint behind a card, not a filled control.
        if (! /\bbg-brand-(600|700)\b(?!\/)/.test(line)) continue
        if (relative in NOT_A_BUTTON) continue

        offenders.push(`${relative}: ${line.trim().slice(0, 90)}`)
      }
    }

    expect(
      offenders,
      'A filled brand control drawn by hand is a second primary button. Use buttonClasses(), ' +
        'as RouteErrorBoundary and SessionExpiryNotice do for exactly this case.',
    ).toEqual([])
  })

  it('takes the secondary recipe from the button system too', () => {
    const offenders: string[] = []

    for (const file of sources()) {
      const relative = path.relative(SOURCE, file)
      if (relative === SYSTEM) continue

      for (const line of readFileSync(file, 'utf8').split('\n')) {
        /*
         * The exact shape of the five copies: an inline-flex box with a
         * radius, horizontal padding and a hover surface. That is a secondary
         * button, whatever element it is written on.
         */
        const looksLikeOne =
          line.includes('inline-flex') &&
          /\brounded\b|\brounded-(lg|md)\b/.test(line) &&
          /\bpx-\d/.test(line) &&
          line.includes('hover:bg-[var(--surface-sunken)]')

        if (looksLikeOne) offenders.push(`${relative}: ${line.trim().slice(0, 90)}`)
      }
    }

    expect(
      offenders,
      'Five copies of this recipe drifted from the system by 6px of height, 4px of radius and ' +
        'one border token. Use buttonClasses(variant, size).',
    ).toEqual([])
  })

  it('is one system with the sizes the system declares', () => {
    const system = readFileSync(path.join(SOURCE, SYSTEM), 'utf8')

    // The floor W5.6 measured. A control shorter than this is a control a
    // thumb misses, so the smallest size the system offers must clear it.
    expect(system, 'The small size must stay at or above the 24px target floor').toMatch(
      /sm:\s*'h-8/,
    )

    expect(
      [...system.matchAll(/^\s{2}(primary|secondary|ghost|danger):/gm)],
      'Four variants, and a fifth is a design decision rather than an edit.',
    ).toHaveLength(4)
  })
})

function sources(): string[] {
  const found: string[] = []

  const walk = (directory: string): void => {
    for (const entry of readdirSync(directory)) {
      const full = path.join(directory, entry)

      if (statSync(full).isDirectory()) {
        if (entry !== '__tests__') walk(full)
        continue
      }

      if (full.endsWith('.tsx') || full.endsWith('.ts')) found.push(full)
    }
  }

  walk(SOURCE)

  return found
}
