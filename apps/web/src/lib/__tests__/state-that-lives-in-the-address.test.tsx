import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { describe, expect, it } from 'vitest'

import { useRouteFocus } from '@/lib/useRouteFocus'
import { useUrlCursor, useUrlPage, useUrlParams } from '@/lib/urlState'

/**
 * W5.7. What the address bar carries, and what it does when somebody edits it.
 *
 * Two properties are asserted here and neither is about layout. The first is
 * that a value off the wire cannot produce a broken screen: every read is
 * bounded, and an unreadable one falls back rather than throwing or asking the
 * API for something absurd. The second is that changing the query string is
 * not a navigation — the W5.6 focus rule — because a filter that pulled focus
 * to the page heading between keystrokes would be unusable.
 */

function Harness() {
  const params = useUrlParams()
  const [page, setPage] = useUrlPage()
  const [cursor, setCursor] = useUrlCursor()
  const location = useLocation()

  return (
    <div>
      <p data-testid="page">{page}</p>
      <p data-testid="cursor">{cursor ?? 'none'}</p>
      <p data-testid="category">{params.choice('category', ['billing', 'cloud'] as const) ?? 'all'}</p>
      <p data-testid="unread">{params.flag('unread') ? 'yes' : 'no'}</p>
      <p data-testid="search">{params.text('q', 8) ?? 'none'}</p>
      <p data-testid="url">{location.search}</p>

      <button type="button" onClick={() => { setPage(page + 1); }}>
        Next page
      </button>
      <button type="button" onClick={() => { setCursor('opaque-cursor'); }}>
        Show older
      </button>
      <button type="button" onClick={() => { params.set({ category: 'billing', cursor: null }); }}>
        Filter to billing
      </button>
    </div>
  )
}

function at(search: string) {
  return render(
    <MemoryRouter initialEntries={[`/activity${search}`]}>
      <Routes>
        <Route path="/activity" element={<Harness />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('reading state out of the address', () => {
  it('reads the values it recognises', () => {
    at('?page=3&category=billing&unread=1&cursor=abc&q=web')

    expect(screen.getByTestId('page')).toHaveTextContent('3')
    expect(screen.getByTestId('category')).toHaveTextContent('billing')
    expect(screen.getByTestId('unread')).toHaveTextContent('yes')
    expect(screen.getByTestId('cursor')).toHaveTextContent('abc')
    expect(screen.getByTestId('search')).toHaveTextContent('web')
  })

  it.each([
    ['?page=0', 'zero'],
    ['?page=-2', 'a negative'],
    ['?page=abc', 'a word'],
    ['?page=3%20pages', 'a number with something after it'],
    ['?page=1e9', 'scientific notation'],
    ['?page=99999999', 'a number past the ceiling'],
    ['?page=', 'an empty value'],
  ])('falls back to the first page for %s (%s)', (search) => {
    /*
     * Every one of these is something a customer can type, a link can be
     * truncated into, or a crawler can invent. The page number decides which
     * page is asked for, so an unreadable one must read as the first page
     * rather than as NaN in a query string.
     */
    at(search)

    expect(screen.getByTestId('page')).toHaveTextContent('1')
  })

  it('ignores a filter value it does not recognise', () => {
    // Rather than passing it to the API, which would answer 422 and turn a
    // mangled link into an error screen.
    at('?category=everything-please')

    expect(screen.getByTestId('category')).toHaveTextContent('all')
  })

  it('ignores search text past the length it accepts', () => {
    at(`?q=${'x'.repeat(200)}`)

    expect(screen.getByTestId('search')).toHaveTextContent('none')
  })

  it('keeps a cursor opaque and never composes one', () => {
    /*
     * The cursor is the server's. A mangled one is still sent, because the
     * API is explicit that an unreadable cursor means the newest page — which
     * is what somebody who pasted half a URL should get.
     */
    at('?cursor=not-a-real-cursor')

    expect(screen.getByTestId('cursor')).toHaveTextContent('not-a-real-cursor')
  })

  it('drops a cursor longer than the API will accept', () => {
    at(`?cursor=${'y'.repeat(600)}`)

    expect(screen.getByTestId('cursor')).toHaveTextContent('none')
  })
})

describe('writing state into the address', () => {
  it('leaves the first page out of the URL', async () => {
    const user = userEvent.setup()

    at('?page=2')

    await user.click(screen.getByRole('button', { name: 'Next page' }))
    expect(screen.getByTestId('url')).toHaveTextContent('page=3')
  })

  it('changes a filter and its cursor in one step', async () => {
    /*
     * One write, for two reasons. A cursor from the unfiltered feed names a
     * row that may not be in the filtered one, and two writes would be two
     * history entries — so Back would need pressing twice to undo one click.
     */
    const user = userEvent.setup()

    at('?cursor=abc')

    await user.click(screen.getByRole('button', { name: 'Filter to billing' }))

    expect(screen.getByTestId('category')).toHaveTextContent('billing')
    expect(screen.getByTestId('cursor')).toHaveTextContent('none')
    expect(screen.getByTestId('url')).not.toHaveTextContent('cursor')
  })

  it('keeps the parameters it was not asked to change', async () => {
    const user = userEvent.setup()

    at('?category=cloud&unread=1')

    await user.click(screen.getByRole('button', { name: 'Next page' }))

    expect(screen.getByTestId('category')).toHaveTextContent('cloud')
    expect(screen.getByTestId('unread')).toHaveTextContent('yes')
  })
})

describe('the W5.6 focus rule, under W5.7 state', () => {
  it('does not move focus when only the query string changes', async () => {
    /*
     * The regression this file exists to prevent. `useRouteFocus` moves focus
     * to the new page's heading on a navigation; a filter, a page number and a
     * cursor all write to the URL, and if any of them counted as a navigation
     * the customer's cursor would be pulled out of the control they were
     * using — on every keystroke, for a search box.
     */
    const user = userEvent.setup()

    function Page() {
      useRouteFocus()

      const [page, setPage] = useUrlPage()

      return (
        <main>
          <h1>Invoices</h1>
          <button type="button" onClick={() => { setPage(page + 1); }}>
            Next page
          </button>
        </main>
      )
    }

    render(
      <MemoryRouter initialEntries={['/invoices']}>
        <Routes>
          <Route path="/invoices" element={<Page />} />
        </Routes>
      </MemoryRouter>,
    )

    const next = screen.getByRole('button', { name: 'Next page' })

    next.focus()
    await user.click(next)
    await user.click(next)

    expect(document.activeElement, 'focus stayed on the control being used').toBe(next)
    expect(screen.getByRole('heading', { level: 1 })).not.toHaveFocus()
  })
})
