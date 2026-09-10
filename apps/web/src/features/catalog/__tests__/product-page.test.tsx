import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ProductPage } from '@/features/catalog/ProductPage'
import '@/i18n'

/**
 * The product page, on the two things Wave 0 settled about it.
 *
 * A product the readiness engine will not sell is not on the API — it 404s
 * by direct link exactly as it is absent from the listing — and the page has
 * to render that as "not here", with no plan, no price and no order button,
 * rather than as an empty product somebody could still try to buy. And the
 * order it does place carries its idempotency key as the header the API
 * reads, not in the body it used to be sent in.
 */

const PRODUCT = {
  id: '01JPRODUCT',
  kind: 'vps',
  slug: 'cloud-vps',
  name: 'Cloud VPS',
  description: 'Virtual machines.',
  plan_count: 1,
  plans: [
    {
      id: '01JPLAN',
      product_id: '01JPRODUCT',
      slug: 'cx-1',
      name: 'CX-1',
      description: null,
      resources: { vcpu: 1, memory_mib: 2048, disk_gib: 20 },
      stock_limit: null,
      per_customer_limit: null,
      prices: [
        {
          billing_period: 'monthly',
          currency: 'KWD',
          recurring: { minor_units: 3000, currency: 'KWD', formatted: 'KWD 3.000' },
          setup: { minor_units: 0, currency: 'KWD', formatted: 'KWD 0.000' },
        },
      ],
    },
  ],
}

function stubFetch(options: {
  product: { status: number; body: unknown }
  onOrder?: (headers: Headers, body: unknown) => void
}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let status = 200
    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      status = 204
    } else if (path.endsWith('/orders')) {
      options.onOrder?.(new Headers(init?.headers), JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      status = 201
      body = { data: { id: '01JORDER', number: 'LYN-000001', status: 'pending_payment' } }
    } else if (path.includes('/catalog/products/')) {
      status = options.product.status
      body = options.product.body
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: status >= 200 && status < 300,
      status,
      statusText: '',
      text: () => Promise.resolve(body === null ? '' : JSON.stringify(body)),
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
          <Route path="/orders/:id" element={<p>order placed</p>} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('the product page', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers nothing to buy for a product the API does not sell', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        product: {
          status: 404,
          body: { error: { code: 'resource.not_found', message: 'The requested resource does not exist.' } },
        },
      }),
    )

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(/does not exist/i)
    expect(screen.queryByRole('button', { name: /place order/i })).not.toBeInTheDocument()
    expect(screen.queryByText(/KWD/)).not.toBeInTheDocument()
  })

  it('places an order with the idempotency key in the header and nothing about price in the body', async () => {
    const ordered = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ product: { status: 200, body: { data: PRODUCT } }, onOrder: ordered }))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /monthly/i }))
    await user.click(screen.getByRole('button', { name: /place order/i }))

    await waitFor(() => {
      expect(ordered).toHaveBeenCalledTimes(1)
    })

    const [headers, body] = ordered.mock.calls[0] as [Headers, Record<string, unknown>]
    expect(headers.get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/)
    expect(body).toEqual({ items: [{ plan_id: '01JPLAN', quantity: 1 }], billing_period: 'monthly' })
    expect(await screen.findByText('order placed')).toBeInTheDocument()
  })
})
