import { act, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { App } from '@/app/App'
import { CONTROL_CENTER_NAV, CUSTOMER_NAV, OPERATOR_NAV } from '@/app/navigation'
import i18n from '@/i18n'
import ar from '@/i18n/locales/ar.json'
import en from '@/i18n/locales/en.json'
import { SHELL_ROUTES } from '@/test-fixtures'

/**
 * The phone drawer offers every destination the desktop does, and only the
 * ones this person may see.
 *
 * Walks the shared navigation definition rather than a list typed into the
 * test: a destination added to navigation.ts is asserted here the same day,
 * and one removed stops being demanded.
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
      // A page the drawer lands on may load its own data; an empty list is
      // enough for a navigation test and keeps the drawer the only subject.
      return Promise.resolve({
        ok: true,
        status: 200,
        statusText: '',
        text: () => Promise.resolve(JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })),
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

function label(key: string, catalogue: typeof en): string {
  return key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown>)[part], catalogue) as string
}

/** Every link in the drawer, keyed by where it goes: two entries may share a name ("Support"), never a destination. */
function linksByDestination(nav: HTMLElement): Map<string, HTMLElement> {
  return new Map(within(nav).getAllByRole('link').map((link) => [link.getAttribute('href') ?? '', link]))
}

async function openDrawer(menuName: RegExp) {
  const user = userEvent.setup()
  const button = await screen.findByRole('button', { name: menuName })
  expect(button).toHaveAttribute('aria-expanded', 'false')
  await user.click(button)
  const drawer = await screen.findByRole('dialog', { name: menuName })
  expect(button).toHaveAttribute('aria-expanded', 'true')
  return { user, button, drawer }
}

describe('the phone drawer', () => {
  afterEach(async () => {
    vi.unstubAllGlobals()
    window.history.replaceState({}, '', '/')
    await i18n.changeLanguage('en')
  })

  it('is opened by a button named as a menu, not as the dashboard', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    const button = await screen.findByRole('button', { name: new RegExp(`^${en.nav.menu}$`, 'i') })
    expect(button).toHaveAttribute('aria-haspopup', 'dialog')
    expect(screen.queryByRole('button', { name: /^dashboard$/i })).not.toBeInTheDocument()
  })

  it('offers every customer destination and none of the operator ones to a customer', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    const { drawer } = await openDrawer(new RegExp(`^${en.nav.menu}$`, 'i'))
    const nav = within(drawer).getByRole('navigation', { name: en.nav.primary })

    const links = linksByDestination(nav)
    for (const item of CUSTOMER_NAV) {
      expect(links.get(item.to)).toHaveTextContent(label(item.labelKey, en))
    }
    expect(links.size).toBe(CUSTOMER_NAV.length)

    for (const item of [...OPERATOR_NAV, ...CONTROL_CENTER_NAV]) {
      expect(links.has(item.to)).toBe(false)
    }

    // The language switch and the way out are in the drawer too.
    expect(within(drawer).getByRole('group', { name: en.common.language })).toBeInTheDocument()
    expect(within(drawer).getByRole('button', { name: en.common.signOut })).toBeInTheDocument()
  })

  it('adds the operator sections for a login that holds an operator permission', async () => {
    vi.stubGlobal('fetch', stubFetch(me(['customer.view_any'])))
    render(<App />)

    const { drawer } = await openDrawer(new RegExp(`^${en.nav.menu}$`, 'i'))
    const nav = within(drawer).getByRole('navigation', { name: en.nav.primary })

    const links = linksByDestination(nav)
    for (const item of [...CUSTOMER_NAV, ...OPERATOR_NAV, ...CONTROL_CENTER_NAV]) {
      expect(links.get(item.to)).toHaveTextContent(label(item.labelKey, en))
    }
    expect(links.size).toBe(CUSTOMER_NAV.length + OPERATOR_NAV.length + CONTROL_CENTER_NAV.length)
  })

  it('closes when a destination is chosen and marks it current', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    const { user, drawer, button } = await openDrawer(new RegExp(`^${en.nav.menu}$`, 'i'))
    await user.click(within(drawer).getByRole('link', { name: en.nav.support }))

    expect(await screen.findByRole('heading', { name: new RegExp(`^${en.nav.support}$`, 'i') })).toBeInTheDocument()
    expect(drawer).not.toHaveAttribute('open')
    expect(button).toHaveAttribute('aria-expanded', 'false')

    await user.click(button)
    expect(within(drawer).getByRole('link', { name: en.nav.support })).toHaveAttribute('aria-current', 'page')
  })

  it('closes on Escape and on the close button', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    render(<App />)

    const { user, drawer, button } = await openDrawer(new RegExp(`^${en.nav.menu}$`, 'i'))
    await user.click(within(drawer).getByRole('button', { name: en.common.close }))
    expect(drawer).not.toHaveAttribute('open')

    await user.click(button)
    expect(drawer).toHaveAttribute('open')
    // jsdom does not run the browser's Escape handling; the cancel event is
    // what Escape produces on a modal dialog.
    act(() => {
      drawer.dispatchEvent(new Event('cancel', { cancelable: true }))
    })
    expect(drawer).not.toHaveAttribute('open')
  })

  it('switches language from inside the drawer, keeps the route, and reads in Arabic', async () => {
    vi.stubGlobal('fetch', stubFetch(me([])))
    window.history.replaceState({}, '', '/support')
    render(<App />)

    const { user, drawer } = await openDrawer(new RegExp(`^${en.nav.menu}$`, 'i'))
    await user.click(within(drawer).getByRole('button', { name: 'العربية' }))

    expect(document.documentElement.dir).toBe('rtl')
    expect(window.location.pathname).toBe('/support')
    expect(await screen.findByRole('dialog', { name: ar.nav.menu })).toBeInTheDocument()
    expect(within(drawer).getByRole('link', { name: ar.nav.support })).toHaveAttribute('aria-current', 'page')
    expect(await screen.findByRole('heading', { name: new RegExp(`^${ar.nav.support}$`) })).toBeInTheDocument()
  })
})
