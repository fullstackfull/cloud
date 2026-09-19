import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ProductPage } from '@/features/catalog/ProductPage'
import '@/i18n'

/**
 * What the customer pays, itemised, before they commit — and every figure of
 * it from the server.
 *
 * The page used to show the plan's recurring price and nothing else, so the
 * setup fee and the tax arrived as a surprise on the invoice. The quote it now
 * asks for runs the same engine that prices the order, so what is shown here
 * is what will be charged.
 */

const PRODUCT = {
  id: '01JPRODUCT',
  slug: 'cloud-vps',
  kind: 'vps',
  name: 'Cloud VPS',
  description: null,
  plans: [
    {
      id: '01JPLAN',
      name: 'Starter',
      description: null,
      resources: { vcpu: 2 },
      prices: [
        {
          billing_period: 'monthly',
          currency: 'KWD',
          recurring: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
          setup: { minor_units: 5000, currency: 'KWD', amount: '5.000' },
        },
      ],
    },
  ],
}

const QUOTE = {
  currency: 'KWD',
  billing_period: 'monthly',
  lines: [
    {
      description: 'Starter',
      plan_id: '01JPLAN',
      quantity: 1,
      unit_recurring: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
      unit_setup: { minor_units: 5000, currency: 'KWD', amount: '5.000' },
      gross: { minor_units: 14000, currency: 'KWD', amount: '14.000' },
      discount: { minor_units: 0, currency: 'KWD', amount: '0.000' },
      tax: { minor_units: 2100, currency: 'KWD', amount: '2.100' },
      total: { minor_units: 16100, currency: 'KWD', amount: '16.100' },
      tax_rate: '0.150000',
      tax_name: 'VAT',
    },
  ],
  setup: { minor_units: 5000, currency: 'KWD', amount: '5.000' },
  subtotal: { minor_units: 14000, currency: 'KWD', amount: '14.000' },
  discount: { minor_units: 0, currency: 'KWD', amount: '0.000' },
  tax: { minor_units: 2100, currency: 'KWD', amount: '2.100' },
  total: { minor_units: 16100, currency: 'KWD', amount: '16.100' },
  coupon_code: null,
  tax_rate: '0.150000',
  tax_name: 'VAT',
  renewal: {
    billing_period: 'monthly',
    subtotal: { minor_units: 9000, currency: 'KWD', amount: '9.000' },
    tax: { minor_units: 1350, currency: 'KWD', amount: '1.350' },
    total: { minor_units: 10350, currency: 'KWD', amount: '10.350' },
    includes_setup: false,
    includes_coupon: false,
  },
}

function stubFetch(onQuote?: (body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/orders/quote')) {
      onQuote?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: QUOTE, meta: { is_quote: true, creates_nothing: true } }
    } else if (path.includes('/catalog/products/cloud-vps')) {
      body = { data: PRODUCT }
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
    <MemoryRouter initialEntries={['/catalogue/cloud-vps']}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/catalogue/:slug" element={<ProductPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

async function choosePlan(): Promise<void> {
  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: /monthly/i }))
}

describe('the price before you buy', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('asks the server what the basket costs as soon as a plan is chosen', async () => {
    const quoted = vi.fn()
    vi.stubGlobal('fetch', stubFetch(quoted))

    renderPage()
    await choosePlan()

    await screen.findByText(/due now/i)

    expect(quoted).toHaveBeenCalledWith(
      expect.objectContaining({ items: [{ plan_id: '01JPLAN', quantity: 1 }], billing_period: 'monthly' }),
    )
  })

  it('names the setup fee, the tax and the total the server sent', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()
    await choosePlan()

    expect(await screen.findByText('One-off setup')).toBeInTheDocument()
    expect(screen.getByText(/Tax \(VAT\)/)).toBeInTheDocument()

    // The server's totals, printed. Nothing on this screen adds them up.
    expect(screen.getByText(/16\.100/)).toBeInTheDocument()
    expect(screen.getByText(/2\.100/)).toBeInTheDocument()
  })

  it('says what it will cost next time, without the setup fee', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()
    await choosePlan()

    expect(await screen.findByText(/then monthly/i)).toBeInTheDocument()
    expect(screen.getByText(/10\.350/)).toBeInTheDocument()
    expect(screen.getByText(/excludes the one-off setup fee/i)).toBeInTheDocument()
  })

  it('says what happens after the payment', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()
    await choosePlan()

    expect(
      await screen.findByText(/service is set up once the payment is confirmed/i),
    ).toBeInTheDocument()
  })

  it('re-prices when the quantity changes rather than multiplying here', async () => {
    const quoted = vi.fn()
    vi.stubGlobal('fetch', stubFetch(quoted))
    const user = userEvent.setup()

    renderPage()
    await choosePlan()
    await screen.findByText(/due now/i)

    // Typed rather than set, because that is how a customer changes it: the
    // point is that every change goes back to the server for a price.
    await user.type(screen.getByLabelText(/quantity/i), '2')

    await vi.waitFor(() => {
      expect(quoted).toHaveBeenCalledWith(
        expect.objectContaining({ items: [{ plan_id: '01JPLAN', quantity: 12 }] }),
      )
    })
  })
})
