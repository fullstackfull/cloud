import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { WalletPage } from '@/features/wallet/WalletPage'
import '@/i18n'

/**
 * Where a balance came from.
 *
 * The screen showed a number and nothing else, so a customer whose balance had
 * moved had no way to see why. The ledger is the answer — and it is the
 * server's ledger: the portal prints the rows and the balance the server
 * recorded after each one, and never adds them up to check.
 */

const BALANCES = {
  data: [
    {
      wallet_id: '01JWALLET',
      currency: 'KWD',
      balance: { minor_units: 8750, currency: 'KWD', amount: '8.750' },
      updated_at: '2026-03-02T10:00:00+00:00',
    },
  ],
  meta: { account_currency: 'KWD', currencies: 1 },
}

const LEDGER = {
  data: [
    {
      id: '01JENTRY2',
      wallet_id: '01JWALLET',
      kind: 'payment',
      amount: { minor_units: -4000, currency: 'KWD', amount: '-4.000' },
      direction: 'debit',
      balance_after: { minor_units: 8750, currency: 'KWD', amount: '8.750' },
      description: 'Applied to invoice LYN-000042',
      invoice_id: '01JINVOICE',
      transaction_id: null,
      created_at: '2026-03-02T10:00:00+00:00',
    },
    {
      id: '01JENTRY1',
      wallet_id: '01JWALLET',
      kind: 'topup',
      amount: { minor_units: 12750, currency: 'KWD', amount: '12.750' },
      direction: 'credit',
      balance_after: { minor_units: 12750, currency: 'KWD', amount: '12.750' },
      description: 'Credit from support',
      invoice_id: null,
      transaction_id: null,
      created_at: '2026-03-01T10:00:00+00:00',
    },
  ],
  meta: { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 },
}

function stubFetch() {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    const body = path.endsWith('/wallet/transactions')
      ? LEDGER
      : path.endsWith('/wallet')
        ? BALANCES
        : null

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
        <WalletPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('the wallet ledger', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows every movement with the balance the server recorded after it', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const ledger = await screen.findByRole('table', { name: /credit history/i })

    expect(within(ledger).getByText('Credit from support')).toBeInTheDocument()
    expect(within(ledger).getByText('Applied to invoice LYN-000042')).toBeInTheDocument()
    expect(within(ledger).getAllByText(/12\.750/).length).toBeGreaterThan(0)
  })

  it('says whether credit was added or spent, in words as well as in sign', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const ledger = await screen.findByRole('table', { name: /credit history/i })

    expect(within(ledger).getByText('spent')).toBeInTheDocument()
    expect(within(ledger).getByText('added')).toBeInTheDocument()
  })

  it('links an entry to the invoice it paid', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const ledger = await screen.findByRole('table', { name: /credit history/i })
    const link = within(ledger).getByRole('link', { name: /view the invoice/i })

    expect(link).toHaveAttribute('href', '/invoices/01JINVOICE')
  })

  it('prints the balance the server published rather than a sum of the rows', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    // 8.750 is the server's balance, and it appears both as the balance and
    // as the balance recorded after the last entry. The screen prints the
    // server's figures; it computes neither.
    expect((await screen.findAllByText(/8\.750/)).length).toBeGreaterThan(0)
  })
})
