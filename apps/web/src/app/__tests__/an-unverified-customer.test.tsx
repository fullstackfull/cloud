import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { AppLayout } from '@/app/AppLayout'
import '@/i18n'

/**
 * What a customer three minutes into their first session is shown.
 *
 * The layout used to state the problem — "your address is not verified" — and
 * offer nothing to do about it, while every money screen refused them with a
 * permissions message. The banner now leads to the page that can resend the
 * link, and the catalogue is still there to read.
 */

function user(verified: boolean) {
  return {
    id: '01JUSER',
    name: 'Amal',
    email: 'amal@example.com',
    email_verified: verified,
    locale: 'en',
    timezone: 'Asia/Kuwait',
    phone: null,
    two_factor_enabled: false,
    last_login_at: null,
    created_at: null,
    customers: [
      {
        id: '01JCUSTOMER',
        type: 'individual',
        status: 'active',
        display_name: 'Amal',
        legal_name: null,
        currency: 'KWD',
        country: 'KW',
        can_purchase: true,
        role: 'owner',
      },
    ],
  }
}

function stubFetch(verified: boolean) {
  return vi.fn(
    (): Promise<Response> =>
      Promise.resolve({
        ok: true,
        status: 200,
        statusText: '',
        text: () => Promise.resolve(JSON.stringify({ data: user(verified) })),
      } as Response),
  )
}

function renderLayout() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter initialEntries={['/catalogue']}>
      <QueryClientProvider client={client}>
        <AppLayout />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('an unverified customer', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('is told what is missing and where to fix it', async () => {
    vi.stubGlobal('fetch', stubFetch(false))

    renderLayout()

    const action = await screen.findByRole('link', { name: /confirm your email address/i })

    expect(action).toHaveAttribute('href', '/verify-email')
  })

  it('can still reach the catalogue, which is the one thing that costs nothing', async () => {
    vi.stubGlobal('fetch', stubFetch(false))

    renderLayout()

    await screen.findByRole('link', { name: /confirm your email address/i })

    expect(screen.getByRole('link', { name: /^catalogue$/i })).toBeInTheDocument()
  })

  it('sees no banner once the address is confirmed', async () => {
    vi.stubGlobal('fetch', stubFetch(true))

    renderLayout()

    await screen.findByRole('link', { name: /^catalogue$/i })

    expect(screen.queryByRole('link', { name: /confirm your email address/i })).not.toBeInTheDocument()
  })
})
