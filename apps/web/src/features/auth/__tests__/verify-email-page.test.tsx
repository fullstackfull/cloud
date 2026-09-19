import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { VerifyEmailPage } from '@/features/auth/VerifyEmailPage'
import '@/i18n'

/**
 * Where the verification link lands.
 *
 * Before this page, the link opened raw JSON in the customer's browser and an
 * unverified customer had nowhere to ask for another one. What these assert is
 * that each of the four things the server can say about a link gets its own
 * sentence, and that the page never claims an address is verified because a
 * query parameter said so.
 */

const UNVERIFIED = {
  id: '01JUSER',
  name: 'Amal',
  email: 'amal@example.com',
  email_verified: false,
  locale: 'en',
  timezone: 'Asia/Kuwait',
  phone: null,
  two_factor_enabled: false,
  last_login_at: null,
  created_at: null,
  customers: [],
}

function stubFetch(user: unknown = UNVERIFIED, onResend?: () => void) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    if (path.endsWith('/email/verify/resend')) {
      onResend?.()

      return Promise.resolve({
        ok: true,
        status: 202,
        statusText: '',
        text: () => Promise.resolve(''),
      } as Response)
    }

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      text: () => Promise.resolve(JSON.stringify({ data: user })),
    } as Response)
  })
}

function renderPage(search = '') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter initialEntries={[`/verify-email${search}`]}>
      <QueryClientProvider client={client}>
        <VerifyEmailPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('the verification page', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('says the address is confirmed when the server confirmed it', async () => {
    vi.stubGlobal('fetch', stubFetch({ ...UNVERIFIED, email_verified: true }))

    renderPage('?status=verified')

    expect(await screen.findByText(/address is confirmed/i)).toBeInTheDocument()
  })

  it('explains an expired link and still offers a new one', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage('?status=expired')

    expect(await screen.findByText(/link has expired/i)).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: /send the link again/i })).toBeInTheDocument()
  })

  it('explains a link that does not match this address', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage('?status=invalid')

    expect(await screen.findByText(/not valid for this address/i)).toBeInTheDocument()
  })

  it('shows the address the link went to, so a typo is visible', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('amal@example.com')).toBeInTheDocument()
  })

  it('sends another link and then holds the button for a while', async () => {
    const resent = vi.fn()
    vi.stubGlobal('fetch', stubFetch(UNVERIFIED, resent))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /send the link again/i }))

    expect(resent).toHaveBeenCalledTimes(1)

    // Held, not hidden: the customer can see that another one is available
    // shortly, rather than wondering where the button went.
    const held = await screen.findByRole('button', { name: /send again in/i })
    expect(held).toBeDisabled()
  })

  it('does not treat a query parameter as proof that the address is verified', async () => {
    // The server says the address is NOT verified; the URL claims it is.
    vi.stubGlobal('fetch', stubFetch())

    renderPage('?status=verified')

    // The resend button is still offered, because the account is what decides.
    expect(await screen.findByRole('button', { name: /send the link again/i })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /continue to the portal/i })).not.toBeInTheDocument()
  })
})
