import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { InvoicePrintPage } from '@/features/billing/InvoicePrintPage'
import '@/i18n'

/**
 * The printable copy: the document, and nothing else on the page.
 *
 * No PDF service was added — the browser already prints, in both languages,
 * on the customer's own paper size. What has to be true is that the page
 * carries the document rather than the application around it.
 */

const INVOICE = {
  id: '01JINVOICE',
  number: 'LYN-000042',
  status: 'paid',
  currency: 'KWD',
  subtotal: { minor_units: 25500, currency: 'KWD', amount: '25.500' },
  discount: { minor_units: 0, currency: 'KWD', amount: '0.000' },
  tax: { minor_units: 0, currency: 'KWD', amount: '0.000' },
  total: { minor_units: 25500, currency: 'KWD', amount: '25.500' },
  amount_paid: { minor_units: 25500, currency: 'KWD', amount: '25.500' },
  amount_due: { minor_units: 0, currency: 'KWD', amount: '0.000' },
  is_payable: false,
  is_settled: true,
  order_id: null,
  issued_at: '2026-02-01T09:00:00+00:00',
  due_at: '2026-02-15T09:00:00+00:00',
  paid_at: '2026-02-02T09:00:00+00:00',
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
      unit_amount: { minor_units: 25500, currency: 'KWD', amount: '25.500' },
      discount: { minor_units: 0, currency: 'KWD', amount: '0.000' },
      tax: { minor_units: 0, currency: 'KWD', amount: '0.000' },
      total: { minor_units: 25500, currency: 'KWD', amount: '25.500' },
      tax_rate: '0',
      tax_name: null,
      period_start: '2026-02-01T00:00:00+00:00',
      period_end: '2026-03-01T00:00:00+00:00',
    },
  ],
  payments: [],
  wallet_credits: [],
}

function stubFetch() {
  return vi.fn(
    (): Promise<Response> =>
      Promise.resolve({
        ok: true,
        status: 200,
        statusText: '',
        text: () => Promise.resolve(JSON.stringify({ data: INVOICE })),
      } as Response),
  )
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter initialEntries={['/invoices/01JINVOICE/print']}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/invoices/:id/print" element={<InvoicePrintPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('the printable invoice', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('carries the number, the address and the lines', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('LYN-000042')).toBeInTheDocument()
    expect(screen.getByText('Premier Care')).toBeInTheDocument()
    expect(screen.getByText('Block 4, Salmiya')).toBeInTheDocument()
    expect(screen.getByText('Cloud VPS — Starter (monthly)')).toBeInTheDocument()
    expect(screen.getAllByText(/25\.500/).length).toBeGreaterThan(0)
  })

  it('carries no application navigation', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    await screen.findByText('LYN-000042')

    expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /dashboard/i })).not.toBeInTheDocument()
  })

  it('names the document in the tab, which is the default file name when saved', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    await screen.findByText('LYN-000042')

    expect(document.title).toBe('Invoice LYN-000042')
  })
})
