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
    const match = Object.keys(routes).find((path) => url.endsWith(path))
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

  it('renders a not-found page for an address the portal does not serve', async () => {
    vi.stubGlobal('fetch', stubFetch(SIGNED_IN))
    visit('/vps/01JEXAMPLE')

    render(<App />)

    expect(await screen.findByRole('heading', { name: /page not found/i })).toBeInTheDocument()
  })
})
