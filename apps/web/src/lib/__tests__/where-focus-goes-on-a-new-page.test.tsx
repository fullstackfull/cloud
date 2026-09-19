import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useRef } from 'react'
import { Link, MemoryRouter, Route, Routes, useSearchParams } from 'react-router'
import { describe, expect, it } from 'vitest'

import { useModalDialog } from '@/lib/useModalDialog'
import { useRouteFocus } from '@/lib/useRouteFocus'

/**
 * W5.6 item 5. The route-focus strategy, pinned.
 *
 * The reason it is pinned rather than left to read: every one of these cases
 * fails silently. Focus that does not move leaves a screen reader saying
 * nothing about a page that entirely changed; focus that moves too eagerly
 * takes the cursor out of a filter box between two keystrokes. Neither is
 * visible in a screenshot, and both are a single line of the hook.
 */

function Shell({ children }: { children?: React.ReactNode }) {
  useRouteFocus()

  return (
    <div>
      <nav>
        <Link to="/invoices">Invoices</Link>
        <Link to="/dashboard">Dashboard</Link>
      </nav>

      <main>{children}</main>
    </div>
  )
}

function Page({ title }: { title: string }) {
  return (
    <>
      <h1>{title}</h1>
      <p>Body of {title}.</p>
    </>
  )
}

function app(initial = '/dashboard') {
  return render(
    <MemoryRouter initialEntries={[initial]}>
      <Routes>
        <Route
          path="/dashboard"
          element={
            <Shell>
              <Page title="Dashboard" />
            </Shell>
          }
        />
        <Route
          path="/invoices"
          element={
            <Shell>
              <Page title="Invoices" />
            </Shell>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
}

describe('arriving at a page', () => {
  it('leaves focus alone on the first load', () => {
    /*
     * The document has just been handed over at the top. Moving focus here
     * would skip past the skip link the customer is about to reach, and would
     * take the cursor out of anything the page autofocused.
     */
    app()

    expect(document.activeElement).toBe(document.body)
  })

  it('moves focus to the heading of the page it opened', async () => {
    const user = userEvent.setup()

    app()

    await user.click(screen.getByRole('link', { name: 'Invoices' }))

    const heading = screen.getByRole('heading', { level: 1, name: 'Invoices' })

    /*
     * The heading, not the landmark: focusing a container announces the
     * container, and what somebody needs to hear is which page they are on.
     * Tab then continues from the content rather than from the eleventh
     * destination in the navigation column.
     */
    expect(document.activeElement).toBe(heading)
  })

  it('does not make the heading a stop everybody has to walk past', async () => {
    const user = userEvent.setup()

    app()

    await user.click(screen.getByRole('link', { name: 'Invoices' }))

    expect(screen.getByRole('heading', { level: 1 })).toHaveAttribute('tabindex', '-1')
  })

  it('falls back to the landmark for a page with no heading', async () => {
    const user = userEvent.setup()

    render(
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route
            path="/dashboard"
            element={
              <Shell>
                <Link to="/invoices">Invoices</Link>
              </Shell>
            }
          />
          <Route path="/invoices" element={<Shell>a page with no heading</Shell>} />
        </Routes>
      </MemoryRouter>,
    )

    await user.click(screen.getAllByRole('link', { name: 'Invoices' })[0] as HTMLElement)

    expect(document.activeElement).toBe(document.querySelector('main'))
  })
})

describe('what is not a new page', () => {
  it('leaves focus in the filter box when the query string changes', async () => {
    /*
     * The case that makes an eager version of this unusable. Filters, sorting
     * and paging are in the URL by design, so a customer typing a hostname
     * into a filter would have the cursor yanked to the heading on the first
     * character — and every one after it.
     */
    const user = userEvent.setup()

    function Filtered() {
      useRouteFocus()

      const [params, setParams] = useSearchParams()

      return (
        <main>
          <h1>Machines</h1>
          <label>
            Filter
            <input
              value={params.get('q') ?? ''}
              onChange={(event) => { setParams({ q: event.target.value }); }}
            />
          </label>
        </main>
      )
    }

    render(
      <MemoryRouter initialEntries={['/vps']}>
        <Routes>
          <Route path="/vps" element={<Filtered />} />
        </Routes>
      </MemoryRouter>,
    )

    const filter = screen.getByLabelText('Filter')

    await user.type(filter, 'web')

    expect(filter).toHaveValue('web')
    expect(document.activeElement).toBe(filter)
  })

  it('leaves a modal dialogue holding its own focus', async () => {
    /*
     * A confirmation that navigates when it is confirmed — which is most of
     * them — must not have focus pulled out from behind it into the page
     * underneath. The dialogue owns focus until it is dismissed, and
     * `useModalDialog` is what gives it back to the control that opened it.
     *
     * The navigating control is inside the dialogue on purpose: in a browser
     * the page behind a modal is inert and nothing out there can be clicked
     * at all, which jsdom does not model.
     */
    const user = userEvent.setup()

    function WithDialog() {
      useRouteFocus()

      const dialog = useRef<HTMLDialogElement>(null)

      useModalDialog(dialog, true)

      return (
        <main>
          <h1>Machines</h1>
          <dialog ref={dialog}>
            <Link to="/vps/done">Confirm</Link>
          </dialog>
        </main>
      )
    }

    render(
      <MemoryRouter initialEntries={['/vps']}>
        <Routes>
          <Route path="/vps" element={<WithDialog />} />
          <Route path="/vps/done" element={<WithDialog />} />
        </Routes>
      </MemoryRouter>,
    )

    const confirm = screen.getByRole('link', { name: 'Confirm' })

    await user.click(confirm)

    // Still inside the dialogue, not on the heading behind it.
    expect(document.activeElement?.closest('dialog')).not.toBeNull()
    expect(screen.getByRole('heading', { level: 1 })).not.toHaveFocus()
  })
})
