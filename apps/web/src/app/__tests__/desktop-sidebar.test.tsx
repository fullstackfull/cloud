import { act, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { App } from '@/app/App'
import { CUSTOMER_NAV, CUSTOMER_NAV_GROUPS, OPERATOR_NAV } from '@/app/navigation'
import i18n from '@/i18n'
import ar from '@/i18n/locales/ar.json'
import en from '@/i18n/locales/en.json'
import { SHELL_ROUTES } from '@/test-fixtures'

/**
 * The desktop sidebar: every destination, always, with nothing behind a
 * disclosure.
 *
 * The audit's AR-4 found the desktop shell putting seventeen of its
 * twenty-two destinations inside a `<details>` element labelled "More", which
 * is how a platform ends up with screens nobody can find. Wave 3 replaced it
 * with a persistent column, and this file is the gate that keeps it gone:
 * every entry in the shared navigation definition is a link in the sidebar,
 * and no control anywhere in the shell is named "More".
 *
 * Walks navigation.ts rather than a list typed into the test, so a destination
 * added there is asserted the same day and one removed stops being demanded.
 */
interface StubbedResponse {
  status: number
  body?: unknown
}

function stubFetch(routes: Record<string, StubbedResponse>) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const match = Object.keys(routes).find((candidate) => path.endsWith(candidate))
    const route = match === undefined ? undefined : routes[match]

    if (route === undefined) {
      return Promise.resolve({
        ok: true,
        status: 200,
        statusText: '',
        text: () =>
          Promise.resolve(
            JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }),
          ),
      } as Response)
    }

    return Promise.resolve({
      ok: route.status >= 200 && route.status < 300,
      status: route.status,
      statusText: '',
      text: () => Promise.resolve(route.body === undefined ? '' : JSON.stringify(route.body)),
    } as Response)
  })
}

function me(permissions: string[]): Record<string, StubbedResponse> {
  return {
    ...SHELL_ROUTES,
    '/me': {
      status: 200,
      body: {
        data: {
          id: '01JEXAMPLE',
          name: 'Sample Customer',
          email: 'customer@lynomia.test',
          email_verified: true,
          locale: 'en',
          timezone: 'Asia/Kuwait',
          phone: null,
          two_factor_enabled: false,
          last_login_at: null,
          created_at: null,
          permissions,
          customers: [],
        },
      },
    },
  }
}

function label(key: string): string {
  return key.split('.').reduce<unknown>(
    (node, part) => (node as Record<string, unknown>)[part],
    en as unknown as Record<string, unknown>,
  ) as string
}

/**
 * The sidebar's own navigation. Named rather than found by position: the phone
 * drawer carries the same label, and only one of the two is in the document at
 * a time in this environment.
 */
async function sidebar(name: string = en.nav.primary): Promise<HTMLElement> {
  const navigations = await screen.findAllByRole('navigation', {
    name: new RegExp(`^${name}$`, 'i'),
  })

  const first = navigations[0]
  expect(first).toBeDefined()

  return first as HTMLElement
}

describe('the desktop sidebar', () => {
  afterEach(async () => {
    vi.unstubAllGlobals()
    window.history.replaceState({}, '', '/')
    await i18n.changeLanguage('en')
  })

  it('offers every customer destination, none of them behind a disclosure', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    const nav = await sidebar()
    const destinations = new Map(
      within(nav)
        .getAllByRole('link')
        .map((link) => [link.getAttribute('href') ?? '', link]),
    )

    for (const item of CUSTOMER_NAV) {
      const link = destinations.get(item.to)
      expect(link, `no sidebar link to ${item.to}`).toBeDefined()
      expect(link).toHaveAccessibleName(label(item.labelKey))
    }

    // Nothing extra: an operator destination in a customer's sidebar would be
    // a link to a screen every request from which is answered 403.
    for (const item of OPERATOR_NAV) {
      expect(destinations.has(item.to)).toBe(false)
    }
  })

  it('has no "More" menu anywhere in the shell', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    await sidebar()

    /*
     * The gate. `<details>` renders as a group with a summary button, and the
     * old shell's was labelled "More" — so both the accessible name and the
     * element are checked. A destination that goes back behind a disclosure
     * fails here rather than in a customer's support ticket.
     */
    expect(screen.queryByRole('button', { name: /^more$/i })).not.toBeInTheDocument()
    expect(screen.queryByText(/^more$/i)).not.toBeInTheDocument()
    expect(document.querySelectorAll('details')).toHaveLength(0)
  })

  it('groups the destinations under the headings the navigation defines', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    const nav = await sidebar()

    for (const group of CUSTOMER_NAV_GROUPS) {
      if (group.labelKey === null) continue

      expect(within(nav).getByText(label(group.labelKey))).toBeInTheDocument()
    }
  })

  it('adds the operator sections for a login that holds an operator permission', async () => {
    vi.stubGlobal('fetch', stubFetch(me(['customer.view_any'])))
    render(<App />)

    const nav = await sidebar()
    const destinations = new Set(
      within(nav)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href') ?? ''),
    )

    for (const item of OPERATOR_NAV) {
      expect(destinations.has(item.to), `no sidebar link to ${item.to}`).toBe(true)
    }
  })

  it('reads in Arabic, right to left, from the same one definition', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    await sidebar()
    await act(async () => {
      await i18n.changeLanguage('ar')
    })

    // The document's own direction, which is what mirrors the sidebar: its
    // border and padding are logical properties rather than left-and-right
    // ones, so there is no second stylesheet to keep in step.
    expect(document.documentElement).toHaveAttribute('dir', 'rtl')

    const nav = await sidebar(ar.nav.primary)
    expect(within(nav).getByRole('link', { name: ar.nav.dashboard })).toBeInTheDocument()
  })
})
