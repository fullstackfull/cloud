import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { InvoicesPage } from '@/features/billing/InvoicesPage'
import '@/i18n'

/**
 * A payment has five outcomes, and this portal used to have one screen for all
 * of them: a button that either navigated away or did nothing at all.
 *
 * Doing nothing is the dangerous half. A declined card looked identical to a
 * payment the provider had not decided yet, so a customer pressed the button
 * again — against money that may already have been moving.
 */

const INVOICE = {
  id: '01JINVOICE',
  number: 'LYN-000042',
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
  issued_at: '2026-03-01T09:00:00+00:00',
  due_at: '2026-03-08T09:00:00+00:00',
  paid_at: null,
}

const PAGE_META = { page: 1, per_page: 25, total: 1, last_page: 1, max_per_page: 100 }

const PAYMENT = {
  id: '01JPAYMENT',
  kind: 'charge',
  status: 'pending',
  amount: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
  invoice_id: '01JINVOICE',
  provider: 'fake',
  is_settled: false,
  failure_code: null,
  failure_message: null,
  processed_at: null,
}

interface NextAction {
  type: string
  redirect_url?: string | null
  client_secret?: string | null
  is_awaiting_provider?: boolean
}

function stubFetch(nextAction: NextAction, failureCode: string | null = null) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/payments')) {
      body = {
        data: {
          payment: PAYMENT,
          provider: 'fake',
          reference: 'fake_pi_requires_action_KWD_9000_abcd1234',
          status: 'requires_action',
          next_action: {
            redirect_url: null,
            client_secret: null,
            is_awaiting_provider: false,
            ...nextAction,
          },
          failure_code: failureCode,
          failure_message: null,
        },
      }
    } else if (path.endsWith('/invoices')) {
      body = { data: [INVOICE], meta: PAGE_META }
    } else {
      body = null
    }

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response)
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <InvoicesPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

async function pay(): Promise<void> {
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: /^pay$/i }))
}

describe('the five answers to paying an invoice', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('a redirect sends the browser to the provider', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({ type: 'redirect', redirect_url: 'https://gateway.test/authorise/abc' }),
    )

    /*
     * Only `assign` is needed, and Location is a host object: spreading it
     * would copy its accessors off their prototype and break the ones this
     * test does not use.
     */
    const assign = vi.fn()
    vi.stubGlobal('location', { href: 'http://localhost/', assign })

    renderPage()
    await pay()

    expect(assign).toHaveBeenCalledWith('https://gateway.test/authorise/abc')
  })

  it('a refusal says why, in the portal’s own words', async () => {
    vi.stubGlobal('fetch', stubFetch({ type: 'failed' }, 'insufficient_funds'))

    renderPage()
    await pay()

    expect(await screen.findByText(/payment was refused/i)).toBeInTheDocument()
    expect(screen.getByText(/not enough funds/i)).toBeInTheDocument()
  })

  it('a payment nobody has decided yet asks the customer to wait and offers no retry', async () => {
    vi.stubGlobal('fetch', stubFetch({ type: 'pending', is_awaiting_provider: true }))

    renderPage()
    await pay()

    expect(await screen.findByText(/do not pay again yet/i)).toBeInTheDocument()

    // The one button on the row is the one that started this. Nothing offers a
    // second attempt while the provider has not answered.
    expect(screen.queryByRole('button', { name: /try again/i })).not.toBeInTheDocument()
  })

  it('a client confirmation keeps the customer here and shows what will be confirmed', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({ type: 'client_confirmation', client_secret: 'fake_pi_secret_abcdef0123456789' }),
    )

    renderPage()
    await pay()

    expect(await screen.findByTestId('payment-client-confirmation')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /confirm the payment/i })).toBeInTheDocument()
  })

  it('a payment the provider has already taken is not reported as a paid invoice', async () => {
    vi.stubGlobal('fetch', stubFetch({ type: 'completed' }))

    renderPage()
    await pay()

    const message = await screen.findByText(/says it has taken this payment/i)

    expect(message).toBeInTheDocument()
    // The word the portal must not use on the strength of a provider's intent.
    expect(message.textContent).toMatch(/marked paid once the provider confirms/i)
  })
})
