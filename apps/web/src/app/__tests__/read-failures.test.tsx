import { render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { App } from '@/app/App'

interface StubbedResponse {
  status: number
  body?: unknown
}

/**
 * As in portal-routing.test.tsx: only what is stubbed may be requested, so a
 * screen that starts calling something new fails loudly instead of quietly
 * rendering an empty state.
 */
function stubFetch(routes: Record<string, StubbedResponse>) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const match = Object.keys(routes).find((candidate) => path.endsWith(candidate))
    const route = match === undefined ? undefined : routes[match]

    if (route === undefined) {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: route.status >= 200 && route.status < 300,
      status: route.status,
      statusText: '',
      text: () => Promise.resolve(route.body === undefined ? '' : JSON.stringify(route.body)),
    } as Response)
  })
}

function signedIn(permissions: string[] = []): Record<string, StubbedResponse> {
  return {
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

const FORBIDDEN: StubbedResponse = {
  status: 403,
  body: { error: { code: 'auth.forbidden', message: 'This action is unauthorized.' } },
}

function visit(path: string) {
  window.history.replaceState({}, '', path)
}

/**
 * A read that fails must not be rendered as a read that returned nothing.
 *
 * These are the two halves of one defect. A query that fails leaves its data
 * undefined; a table handed undefined shows its empty state; so a 403, a
 * dropped connection or a database outage all came out as "you have nothing
 * here" — stated with no hint that anything had gone wrong. And in the wallet's
 * case the screen said it about data the API had actually sent.
 */
describe('a failed read is reported, not rendered as emptiness', () => {
  beforeEach(() => {
    visit('/')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('says so when the services list is refused', async () => {
    vi.stubGlobal('fetch', stubFetch({ ...signedIn(), '/services': FORBIDDEN }))
    visit('/services')

    render(<App />)

    expect(await screen.findByRole('alert')).toHaveTextContent(/not permitted/i)
  })

  it('says so when an operator may not see the compute fleet', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        ...signedIn(['invoice.view_any']),
        '/infrastructure/nodes': FORBIDDEN,
        '/infrastructure/hosting-nodes': FORBIDDEN,
      }),
    )
    visit('/admin/infrastructure')

    render(<App />)

    // "There are no compute nodes" and "you may not look at the compute nodes"
    // are opposite facts, and this operator holds a billing permission only.
    expect(await screen.findByRole('alert')).toHaveTextContent(/not permitted/i)
  })

  it('shows a wallet balance the API actually returned', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        ...signedIn(),
        '/wallet': {
          status: 200,
          // The real shape: a list, one balance per currency, and a meta block
          // naming the account's own. Reading `.balance` off this collection is
          // what told a funded customer they had no wallet.
          body: {
            data: [
              {
                wallet_id: '01JQ8M2V9K3D7F5H1N0P2R4S6T',
                currency: 'KWD',
                balance: { minor_units: 12750, currency: 'KWD', amount: '12.750' },
                updated_at: '2026-01-04T10:00:00+00:00',
              },
            ],
            meta: { account_currency: 'KWD', currencies: 1 },
          },
        },
      }),
    )
    visit('/wallet')

    render(<App />)

    // Three decimal places, because KWD has three. Two would be a different
    // amount.
    expect(await screen.findByText(/12\.750/)).toBeInTheDocument()
    expect(screen.queryByText(/no wallet yet/i)).not.toBeInTheDocument()
  })

  it('shows every currency the account holds, since the API never sums them', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        ...signedIn(),
        '/wallet': {
          status: 200,
          body: {
            data: [
              {
                wallet_id: '01JQ8M2V9K3D7F5H1N0P2R4S6T',
                currency: 'KWD',
                balance: { minor_units: 12750, currency: 'KWD', amount: '12.750' },
                updated_at: '2026-01-04T10:00:00+00:00',
              },
              {
                wallet_id: null,
                currency: 'USD',
                balance: { minor_units: 0, currency: 'USD', amount: '0.00' },
                updated_at: null,
              },
            ],
            meta: { account_currency: 'KWD', currencies: 2 },
          },
        },
      }),
    )
    visit('/wallet')

    render(<App />)

    expect(await screen.findByText(/12\.750/)).toBeInTheDocument()
    // The second balance is not dropped, and it is not added to the first:
    // there is no rate the platform would honour.
    expect(screen.getByText(/0\.00/)).toBeInTheDocument()
    expect(screen.getByText(/no credit has moved yet/i)).toBeInTheDocument()
  })
})
