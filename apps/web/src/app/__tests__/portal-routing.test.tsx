import { render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { App } from '@/app/App'

interface StubbedResponse {
  status: number
  body?: unknown
}

/**
 * Answers the portal's requests without a server.
 *
 * Only the endpoints under test are stubbed; anything else fails the test
 * loudly rather than silently returning an empty body, so a page that starts
 * calling something new cannot pass by accident.
 */
function stubFetch(routes: Record<string, StubbedResponse>) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)

    // Compared on the path alone. Matching the whole URL would mean every
    // stub had to know the paging parameters the hook happens to send, and a
    // test would break when a default changed rather than when behaviour did.
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

const SIGNED_OUT: Record<string, StubbedResponse> = {
  '/me': {
    status: 401,
    body: { error: { code: 'auth.unauthenticated', message: 'Unauthenticated.' } },
  },
}

const SIGNED_IN: Record<string, StubbedResponse> = {
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
        permissions: [],
        customers: [],
      },
    },
  },
}

function visit(path: string) {
  window.history.replaceState({}, '', path)
}

describe('portal routing', () => {
  beforeEach(() => {
    visit('/')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('sends a signed-out visitor to the sign-in page', async () => {
    vi.stubGlobal('fetch', stubFetch(SIGNED_OUT))

    render(<App />)

    // The heading rather than the URL: what matters is that the customer is
    // looking at the sign-in form, not that a redirect happened internally.
    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument()
  })

  it('keeps a signed-out visitor away from the security page', async () => {
    vi.stubGlobal('fetch', stubFetch(SIGNED_OUT))
    visit('/security')

    render(<App />)

    expect(await screen.findByRole('heading', { name: /sign in/i })).toBeInTheDocument()
    expect(screen.queryByText(/sign-in history/i)).not.toBeInTheDocument()
  })

  it('shows the dashboard to a signed-in customer', async () => {
    vi.stubGlobal('fetch', stubFetch(SIGNED_IN))

    render(<App />)

    expect(await screen.findByRole('heading', { name: /welcome, sample customer/i })).toBeInTheDocument()
  })

  it('keeps a signed-in customer off the sign-in page', async () => {
    vi.stubGlobal('fetch', stubFetch(SIGNED_IN))
    visit('/sign-in')

    render(<App />)

    expect(
      await screen.findByRole('heading', { name: /welcome, sample customer/i }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: /sign in/i })).not.toBeInTheDocument()
  })

  it('shows the catalogue a signed-in customer can buy from', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        ...SIGNED_IN,
        '/catalog/products': {
          status: 200,
          body: {
            data: [
              {
                id: '01JPRODUCT',
                kind: 'vps',
                slug: 'cloud-vps',
                name: 'Cloud VPS',
                description: 'Virtual machines.',
                plan_count: 4,
              },
            ],
            meta: { page: 1, per_page: 25, total: 1, last_page: 1, max_per_page: 100 },
          },
        },
      }),
    )
    visit('/catalogue')

    render(<App />)

    expect(await screen.findByRole('heading', { name: /cloud vps/i })).toBeInTheDocument()
    expect(screen.getByText(/4 plans available/i)).toBeInTheDocument()
  })

  it('never renders an amount without its currency', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        ...SIGNED_IN,
        '/invoices': {
          status: 200,
          body: {
            data: [
              {
                id: '01JINVOICE',
                number: 'INV-000001',
                status: 'open',
                currency: 'KWD',
                subtotal: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
                discount: { minor_units: 0, currency: 'KWD', amount: '0.000' },
                tax: { minor_units: 0, currency: 'KWD', amount: '0.000' },
                total: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
                amount_paid: { minor_units: 0, currency: 'KWD', amount: '0.000' },
                amount_due: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
                is_payable: true,
                is_settled: false,
                order_id: null,
                items_count: 1,
                issued_at: null,
                due_at: null,
                paid_at: null,
              },
            ],
            meta: { page: 1, per_page: 25, total: 1, last_page: 1, max_per_page: 100 },
          },
        },
      }),
    )
    visit('/invoices')

    render(<App />)

    expect(await screen.findByText('INV-000001')).toBeInTheDocument()

    // A bare "9.000" would be a number a customer has to guess the currency of.
    // Two amounts on this row, both carrying KWD.
    expect(screen.getAllByText(/KWD/).length).toBeGreaterThanOrEqual(2)
  })

  it('renders a not-found page for an address the portal does not serve', async () => {
    vi.stubGlobal('fetch', stubFetch(SIGNED_IN))
    visit('/vps/01JEXAMPLE')

    render(<App />)

    expect(await screen.findByRole('heading', { name: /page not found/i })).toBeInTheDocument()
  })
})
