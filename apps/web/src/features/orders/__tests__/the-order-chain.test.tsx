import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { OrderDetailPage } from '@/features/orders/OrderDetailPage'
import '@/i18n'

/**
 * An order says what it produced: the invoice, the payment, and the services
 * that now exist because it was paid.
 *
 * The links are the server's. A screen that matched an invoice to an order by
 * amount and date would be a screen that eventually shows the wrong invoice,
 * and the customer would pay it.
 */

const ORDER = {
  id: '01JORDER',
  number: 'ORD-000042',
  status: 'paid',
  currency: 'KWD',
  subtotal: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
  discount: { minor_units: 0, currency: 'KWD', amount: '0.000' },
  tax: { minor_units: 0, currency: 'KWD', amount: '0.000' },
  total: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
  is_paid: true,
  is_cancellable: false,
  coupon_code: null,
  items: [
    {
      id: '01JITEM',
      kind: 'plan',
      description: 'Cloud VPS — Starter',
      quantity: 1,
      total: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
    },
  ],
  invoice_id: '01JINVOICE',
  invoice_number: 'LYN-000042',
  services: [
    {
      id: '01JSERVICE',
      kind: 'vps',
      identity: 'web-01',
      state: 'running',
      is_usable: true,
    },
  ],
  placed_at: '2026-03-01T09:00:00+00:00',
  paid_at: '2026-03-01T09:05:00+00:00',
  cancelled_at: null,
}

function stubFetch(order: Record<string, unknown> = ORDER) {
  return vi.fn(
    (): Promise<Response> =>
      Promise.resolve({
        ok: true,
        status: 200,
        statusText: '',
        text: () => Promise.resolve(JSON.stringify({ data: order })),
      } as Response),
  )
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter initialEntries={['/orders/01JORDER']}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/orders/:id" element={<OrderDetailPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('the order chain', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('links the invoice the order produced, by id rather than by amount', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const link = await screen.findByRole('link', { name: /LYN-000042/ })

    expect(link).toHaveAttribute('href', '/invoices/01JINVOICE')
  })

  it('says whether the payment has been received', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText(/what happens next/i)).toBeInTheDocument()
    expect(screen.getByText(/^received$/i)).toBeInTheDocument()
  })

  it('names the service that exists because the order was paid', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('web-01')).toBeInTheDocument()
  })

  it('says the service follows the payment when the order is not paid yet', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({ ...ORDER, status: 'pending_payment', is_paid: false, paid_at: null, services: [] }),
    )

    renderPage()

    expect(await screen.findByText(/not paid yet/i)).toBeInTheDocument()
    expect(screen.getByText(/set up once the payment is confirmed/i)).toBeInTheDocument()
  })
})
