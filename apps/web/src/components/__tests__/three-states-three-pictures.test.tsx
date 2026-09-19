import { readFileSync } from 'node:fs'
import path from 'node:path'

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { EmptyState } from '@/components/EmptyState'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { ApiError } from '@/lib/api'

/**
 * §12, §25. Waiting, nothing, and broken are three different pictures.
 *
 * W5.5 made the three states mean different things. What it did not do was
 * make two of them *look* different: `Loading` and `EmptyState` rendered the
 * same paragraph with the same four classes —
 * `py-8 text-center text-sm text-[var(--text-muted)]` — so the only thing
 * separating "your invoices are on their way" from "you have no invoices" was
 * the sentence. On a slow connection the moment the answer arrived was
 * invisible: same box, same colour, same position, new words.
 *
 * A failed read was already distinct, because it is an alert rather than a
 * line of muted text, and that part is asserted here too so that a later
 * tidy-up cannot quietly flatten it into the other two.
 */

const COMPONENTS = path.resolve(import.meta.dirname, '..')

describe('the three things a list can say', () => {
  it('shows a turning mark while it waits, and nothing of the kind when it is empty', () => {
    const { container: waiting } = render(<Loading />)
    const { container: empty } = render(<EmptyState>Nothing here yet.</EmptyState>)

    /*
     * Asserted on the graphic rather than on a class string: what matters is
     * that a customer sees a different picture, and `svg` is the picture.
     * `aria-hidden`, because the sentence beside it already says it.
     */
    const mark = waiting.querySelector('svg')

    expect(mark, 'the loading state must show something more than a sentence').not.toBeNull()
    expect(mark?.getAttribute('aria-hidden')).toBe('true')

    expect(
      empty.querySelector('svg'),
      'an empty state is the quiet one — a mark here would make it look busy',
    ).toBeNull()
  })

  it('still announces waiting as a status, so the mark did not replace the words', () => {
    render(<Loading />)

    // W5.6's guarantee. The picture is new; the announcement is unchanged.
    expect(screen.getByRole('status')).toHaveTextContent(/\S/)
  })

  it('draws a failed read as an alert rather than as a quiet line', () => {
    render(<LoadFailure error={new ApiError(500, { code: 'server_error', message: 'Broken.' })} />)

    /*
     * The distinction that §12 names explicitly. An alert has a tinted
     * surface, a border and a role; an empty state has none of the three, so
     * the two cannot be mistaken for one another at a glance.
     */
    expect(screen.getByRole('alert')).toBeInTheDocument()
  })

  it('keeps the two quiet states from collapsing back into one box', () => {
    /*
     * The source-level half of the same claim, and the one that would have
     * caught this in the first place. Before W5.8 these two strings were
     * character-for-character identical.
     */
    const waiting = classesIn('Loading.tsx')
    const empty = classesIn('EmptyState.tsx')

    expect(waiting, 'Loading declares no classes to compare').not.toEqual(new Set())
    expect(empty, 'EmptyState declares no classes to compare').not.toEqual(new Set())

    expect(
      [...waiting].sort(),
      'Loading and EmptyState are the same box again. Three states, three pictures.',
    ).not.toEqual([...empty].sort())
  })
})

/** The utility classes a component's own file names, as a set. */
function classesIn(file: string): Set<string> {
  const source = readFileSync(path.join(COMPONENTS, file), 'utf8')
  const found = new Set<string>()

  // Both quote styles: EmptyState writes its classes in a JSX attribute,
  // Loading passes them to cn() as string literals.
  for (const match of source.matchAll(/["']([^"']*(?:text-|py-|flex|items-)[^"']*)["']/g)) {
    for (const each of (match[1] ?? '').split(/\s+/)) {
      if (each !== '') found.add(each)
    }
  }

  return found
}
