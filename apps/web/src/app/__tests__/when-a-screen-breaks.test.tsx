import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { RouteErrorBoundary } from '@/app/RouteErrorBoundary'
import '@/i18n'

/**
 * §33. What a customer sees when a screen throws.
 *
 * Before the boundary existed the answer was a white page. React unmounts the
 * whole tree when an error escapes render, and with no boundary above the
 * routes that tree is the entire portal — the sidebar, the language switcher
 * and the sign-out button go with it. The customer's only move is to guess
 * that reloading might help.
 *
 * ## The crash is deliberate and lives here
 *
 * The component below exists only in this file. There is no `?crash=1`
 * parameter, no `/debug/throw` route and no exported throwing component —
 * a production route that crashes on request is a denial-of-service primitive
 * with a friendly name, and a crash component exported from `src/` is one
 * import away from being rendered by accident.
 *
 * The boundary under test is the real one the routes use.
 */

/**
 * Whether the screen under test is currently broken.
 *
 * A module flag rather than a prop or a self-clearing latch, and that is not
 * incidental: React re-renders a throwing tree before it gives up on it, so a
 * component that throws only on its *first* render succeeds on React's own
 * retry and the boundary never shows. The first draft of this file did exactly
 * that and two of its tests passed for the wrong reason.
 *
 * With a flag the test owns, "broken" means broken until the test says
 * otherwise — which is also the honest model of the thing being tested: a
 * transient fault that has since cleared is what the retry button is for.
 */
let broken = true

function Explodes() {
  if (broken) {
    throw new TypeError("Cannot read properties of undefined (reading 'hostname')")
  }

  return <p>The screen rendered.</p>
}

function Fine() {
  return <p>The screen rendered.</p>
}

function renderBoundary(children: React.ReactNode) {
  // React logs caught errors to console.error by design; silenced so a passing
  // test does not print a stack that looks like a failure.
  const quiet = vi.spyOn(console, 'error').mockImplementation(() => undefined)

  const result = render(
    <MemoryRouter initialEntries={['/vps/01JMACHINE']}>
      <RouteErrorBoundary>{children}</RouteErrorBoundary>
    </MemoryRouter>,
  )

  return { ...result, quiet }
}

describe('a screen that throws while rendering', () => {
  beforeEach(() => {
    broken = true
  })

  it('shows the customer an apology instead of a white page', () => {
    const { quiet } = renderBoundary(<Explodes />)

    expect(screen.getByText(/something went wrong while showing this page/i)).toBeInTheDocument()

    quiet.mockRestore()
  })

  it('never shows the exception, the stack, or the component path', () => {
    const { container, quiet } = renderBoundary(<Explodes />)

    const rendered = container.textContent

    // Asserted first, so that "shows nothing at all" cannot pass this test by
    // containing none of the strings below.
    expect(rendered).toMatch(/something went wrong while showing this page/i)

    /*
     * A `TypeError` naming an internal symbol tells the customer nothing and
     * tells anybody reading over their shoulder something about how the
     * platform is built. All four of these are things a careless fallback
     * prints.
     */
    expect(rendered).not.toMatch(/TypeError|ReferenceError|undefined is not|Cannot read/i)
    expect(rendered).not.toMatch(/hostname/)
    expect(rendered).not.toMatch(/\bat \w+ \(|\.tsx:|\.js:|webpack|vite/i)
    expect(rendered).not.toMatch(/stack|exception/i)

    quiet.mockRestore()
  })

  it('does not invent a reference number nobody could look up', () => {
    /*
     * There is no client error telemetry in this platform. A reference here
     * would be a string the customer quotes to support and is told means
     * nothing — worse than offering none.
     */
    const { container, quiet } = renderBoundary(<Explodes />)

    const rendered = container.textContent

    expect(rendered).toMatch(/something went wrong while showing this page/i)
    expect(rendered).not.toMatch(/reference|ticket #|error id|correlation/i)

    quiet.mockRestore()
  })

  it('says the true thing about whether data changed', () => {
    // Drawing a page does not change data. What the customer asked for before
    // it either happened on the server or did not, and that is where they find
    // out — so the copy points at the dashboard rather than reassuring them.
    const { quiet } = renderBoundary(<Explodes />)

    expect(screen.getByText(/nothing was changed by this error/i)).toBeInTheDocument()

    quiet.mockRestore()
  })

  it('offers a retry that actually re-renders the screen', async () => {
    const { quiet } = renderBoundary(<Explodes />)
    const user = userEvent.setup()

    expect(screen.queryByText('The screen rendered.')).not.toBeInTheDocument()

    // The transient fault clears, which is the case the button is for.
    broken = false

    await user.click(screen.getByRole('button', { name: /try again/i }))

    // The second render succeeds, and the customer is back on the page rather
    // than on a permanent apology.
    expect(screen.getByText('The screen rendered.')).toBeInTheDocument()

    quiet.mockRestore()
  })

  it('offers a way out when retrying will not help', () => {
    const { quiet } = renderBoundary(<Explodes />)

    // The dashboard escapes a screen that will always throw.
    expect(screen.getByRole('link', { name: /go to dashboard/i })).toHaveAttribute('href', '/')

    quiet.mockRestore()
  })

  it('carries the route to support, because that is what support asks first', () => {
    const { quiet } = renderBoundary(<Explodes />)

    const support = screen.getByRole('link', { name: /contact support/i })
    const href = support.getAttribute('href') ?? ''

    expect(href).toContain('/support')
    expect(href).toContain(encodeURIComponent('/vps/01JMACHINE'))

    quiet.mockRestore()
  })

  it('stays out of the way when nothing throws', () => {
    render(
      <MemoryRouter>
        <RouteErrorBoundary>
          <Fine />
        </RouteErrorBoundary>
      </MemoryRouter>,
    )

    expect(screen.getByText('The screen rendered.')).toBeInTheDocument()
    expect(screen.queryByText(/something went wrong/i)).not.toBeInTheDocument()
  })

  it('clears itself when the customer navigates somewhere else', async () => {
    /*
     * A caught error is about one screen. Without this the customer carries the
     * apology for a page they have left, and every subsequent click appears to
     * do nothing.
     */
    function Wrapper() {
      const [path, setPath] = useState('/a')

      return (
        <MemoryRouter initialEntries={['/a']} key={path}>
          <button type="button" onClick={() => { setPath('/b'); }}>
            Go elsewhere
          </button>
          <RouteErrorBoundary>
            {path === '/a' ? <Explodes /> : <Fine />}
          </RouteErrorBoundary>
        </MemoryRouter>
      )
    }

    const quiet = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const user = userEvent.setup()

    render(<Wrapper />)
    expect(screen.getByText(/something went wrong while showing this page/i)).toBeInTheDocument()

    // The other screen is fine; only the one navigated away from was broken.
    broken = false

    await user.click(screen.getByRole('button', { name: 'Go elsewhere' }))

    expect(screen.getByText('The screen rendered.')).toBeInTheDocument()
    expect(screen.queryByText(/something went wrong while showing this page/i)).not.toBeInTheDocument()

    quiet.mockRestore()
  })
})

describe('the portal source', () => {
  it('ships no route or flag that makes a screen crash on request', async () => {
    /*
     * The crash in this file is a local component. A production path that
     * throws on request — `?crash=1`, `/debug/throw`, an exported
     * `<Boom />` — is a denial-of-service primitive with a friendly name, and
     * the reason this test exists is that adding one is the obvious way to
     * demonstrate an error boundary by hand.
     */
    const { readdirSync, readFileSync, statSync } = await import('node:fs')
    const path = await import('node:path')

    const root = path.resolve(import.meta.dirname, '../..')
    const files: string[] = []

    const walk = (directory: string): void => {
      for (const entry of readdirSync(directory)) {
        if (entry === 'node_modules' || entry === '__tests__') continue

        const full = path.join(directory, entry)

        if (statSync(full).isDirectory()) walk(full)
        else if (full.endsWith('.ts') || full.endsWith('.tsx')) files.push(full)
      }
    }

    walk(root)

    const offenders = files.filter((file) => {
      const source = readFileSync(file, 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '')

      return /crash=|\/debug\/throw|forceCrash|__crash|triggerCrash/.test(source)
    })

    expect(
      offenders.map((file) => path.relative(root, file)),
      'A crash-on-demand path in shipped code is a denial-of-service primitive with a friendly name.',
    ).toEqual([])
  })
})
