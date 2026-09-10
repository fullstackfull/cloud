import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { InvoiceDetailPage } from '@/features/billing/InvoiceDetailPage'
import '@/i18n'

/**
 * What a customer needs from an invoice: the lines, the address it was issued
 * to, and what happened to their money — including the card that was refused.
 *
 * Every figure in these fixtures is minor units plus a currency, and every
 * assertion below is that the screen printed the server's number. Nothing here
 * checks arithmetic, because the portal is not allowed to do any.
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
  amount_paid: { minor_units: 4000, currency: 'KWD', amount: '4.000' },
  amount_due: { minor_units: 5000, currency: 'KWD', amount: '5.000' },
  is_payable: true,
  is_settled: false,
  order_id: '01JORDER',
  subscription_id: null,
  issued_at: '2026-03-01T09:00:00+00:00',
  due_at: '2026-03-08T09:00:00+00:00',
  paid_at: null,
  billing_snapshot: {
    display_name: 'Premier Care',
    tax_id: 'KW-TAX-4471',
    address: { line1: 'Block 4, Salmiya', country: 'KW' },
  },
  items: [
    {
      id: '01JITEM',
      kind: 'plan',
      description: 'Cloud VPS — Starter (monthly)',
      quantity: 1,
      unit_amount: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
      discount: { minor_units: 0, currency: 'KWD', amount: '0.000' },
      tax: { minor_units: 0, currency: 'KWD', amount: '0.000' },
      total: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
      tax_rate: '0',
      tax_name: null,
      period_start: '2026-03-01T00:00:00+00:00',
      period_end: '2026-04-01T00:00:00+00:00',
    },
  ],
  payments: [
    {
      id: '01JFAILED',
      provider: 'fake',
      kind: 'charge',
      status: 'failed',
      is_settled: false,
      amount: { minor_units: 5000, currency: 'KWD', amount: '5.000' },
      failure_code: 'card_declined',
      processed_at: '2026-03-02T09:00:00+00:00',
      created_at: '2026-03-02T09:00:00+00:00',
    },
  ],
  wallet_credits: [
    {
      id: '01JCREDIT',
      kind: 'payment',
      amount: { minor_units: -4000, currency: 'KWD', amount: '-4.000' },
      direction: 'debit',
      balance_after: { minor_units: 0, currency: 'KWD', amount: '0.000' },
      description: 'Applied to invoice',
      created_at: '2026-03-01T10:00:00+00:00',
    },
  ],
}

function stubFetch() {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)

    const body = url.includes('/invoices/01JINVOICE') ? { data: INVOICE } : null

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
    <MemoryRouter initialEntries={['/invoices/01JINVOICE']}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('an invoice is a document', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the billing details the invoice was issued against', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('Premier Care')).toBeInTheDocument()
    expect(screen.getByText('Block 4, Salmiya')).toBeInTheDocument()
    expect(screen.getByText('KW-TAX-4471')).toBeInTheDocument()
  })

  it('itemises what was bought, for which period', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const lines = await screen.findByRole('table', { name: /what you bought/i })

    expect(within(lines).getByText('Cloud VPS — Starter (monthly)')).toBeInTheDocument()

    // The period the line covers, in the named-month format the portal uses
    // everywhere rather than a numeric date somebody has to decode.
    expect(within(lines).getByText(/Mar 01, 2026.*Apr 01, 2026/)).toBeInTheDocument()
  })

  it('shows a payment that failed, and why', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const payments = await screen.findByRole('table', { name: /payments on this invoice/i })

    expect(within(payments).getByText(/declined by the bank/i)).toBeInTheDocument()
    expect(within(payments).getByText(/^failed$/i)).toBeInTheDocument()
  })

  it('shows wallet credit as its own row rather than folding it into the total', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const credits = await screen.findByRole('table', { name: /credit applied to this invoice/i })

    expect(within(credits).getByText(/spent on an invoice/i)).toBeInTheDocument()
    // The server's own signed figure, printed as it was sent, and the
    // direction in words beside it rather than a minus sign alone.
    expect(within(credits).getByText(/4\.000/)).toBeInTheDocument()
  })

  it('offers the printable document rather than generating one here', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const print = await screen.findByRole('link', { name: /print/i })

    expect(print).toHaveAttribute('href', '/invoices/01JINVOICE/print')
  })
})
