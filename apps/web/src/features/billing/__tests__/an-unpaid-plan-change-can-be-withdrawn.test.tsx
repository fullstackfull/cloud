import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { InvoiceDetailPage } from '@/features/billing/InvoiceDetailPage'
import '@/i18n'

/**
 * A plan change that can no longer be delivered cannot be paid, and its open
 * invoice held every other change of plan: the customer had no way out but to
 * wait for the renewal (N2 / X7-2). The invoice now says whether the change
 * can be withdrawn, and the screen offers exactly that - with no Pay button
 * when the server says a payment would be refused.
 */

const money = (minor: number) => ({ minor_units: minor, currency: 'KWD', amount: (minor / 1000).toFixed(3) })

function invoice(overrides: Record<string, unknown>) {
  return {
    id: '01JPLANCHANGE',
    number: 'LYN-000077',
    status: 'open',
    currency: 'KWD',
    subtotal: money(7500),
    discount: money(0),
    tax: money(0),
    total: money(7500),
    amount_paid: money(0),
    amount_due: money(7500),
    is_payable: false,
    is_settled: false,
    plan_change_withdrawable: true,
    order_id: null,
    subscription_id: '01JSUBSCRIPTION',
    issued_at: '2026-04-21T00:00:00+00:00',
    due_at: '2026-04-28T00:00:00+00:00',
    paid_at: null,
    billing_snapshot: {},
    items: [],
    payments: [],
    wallet_credits: [],
    ...overrides,
  }
}

function stubFetch(document: Record<string, unknown>, calls: string[]) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    calls.push(`${init?.method ?? 'GET'} ${url}`)

    const body = url.endsWith('/withdraw-plan-change')
      ? { data: { ...document, status: 'void', is_payable: false, plan_change_withdrawable: false } }
      : { data: document }

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
    <MemoryRouter initialEntries={['/invoices/01JPLANCHANGE']}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('an unpaid plan change can be withdrawn', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers the way out, and no Pay button, when the change cannot be paid', async () => {
    const calls: string[] = []
    vi.stubGlobal('fetch', stubFetch(invoice({}), calls))

    renderPage()

    const withdraw = await screen.findByRole('button', { name: 'Withdraw this plan change' })
    expect(screen.queryByRole('button', { name: 'Pay' })).toBeNull()
    expect(screen.getByText(/cannot be made right now, so the invoice cannot be paid/)).toBeTruthy()

    // One click asks; nothing is sent until the customer confirms.
    fireEvent.click(withdraw)
    expect(await screen.findByText('Withdraw this plan change?')).toBeTruthy()
    const posted = () => calls.some((call) => call.startsWith('POST ') && call.endsWith('/invoices/01JPLANCHANGE/withdraw-plan-change'))
    expect(posted()).toBe(false)

    // Keeping it sends nothing.
    fireEvent.click(screen.getByRole('button', { name: 'Keep the change' }))
    expect(posted()).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'Withdraw this plan change' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Withdraw the change' }))

    await waitFor(() => {
      expect(posted()).toBe(true)
    })
  })

  it('offers both when the change is payable, and neither withdraw when the server says no', async () => {
    vi.stubGlobal('fetch', stubFetch(invoice({ is_payable: true }), []))
    const first = renderPage()

    expect(await screen.findByRole('button', { name: 'Pay' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Withdraw this plan change' })).toBeTruthy()
    first.unmount()
    vi.unstubAllGlobals()

    vi.stubGlobal('fetch', stubFetch(invoice({ is_payable: true, plan_change_withdrawable: false }), []))
    renderPage()

    expect(await screen.findByRole('button', { name: 'Pay' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Withdraw this plan change' })).toBeNull()
  })
})
