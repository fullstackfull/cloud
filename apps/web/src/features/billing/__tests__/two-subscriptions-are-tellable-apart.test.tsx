import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { SubscriptionsPage } from '@/features/billing/SubscriptionsPage'
import '@/i18n'

/**
 * Two monthly subscriptions used to be two identical rows, and cancelling one
 * of them was a guess with somebody's production machine on the other side.
 * Now each row says which plan it is on and which machine it runs, and the
 * cancellation dialogue repeats it.
 */

function subscription(id: string, plan: string, hostname: string | null, amount: number) {
  return {
    id,
    status: 'active',
    currency: 'KWD',
    billing_period: 'monthly',
    recurring_amount: { minor_units: amount, currency: 'KWD', amount: (amount / 1000).toFixed(3) },
    plan_id: `plan-${id}`,
    plan: { id: `plan-${id}`, name: plan, billing_period: 'monthly' },
    product: { id: 'product-vps', kind: 'vps', name: 'Cloud VPS' },
    services: [
      {
        id: `service-${id}`,
        kind: 'vps',
        label: `Cloud VPS — ${plan}`,
        identity: hostname,
        // The handle the API publishes: the machine's own id, not the
        // service's, which is what a link to the machine needs.
        resource: hostname === null ? null : { kind: 'vps', id: `vm-${id}` },
        state: hostname === null ? 'preparing' : 'running',
        is_usable: hostname !== null,
      },
    ],
    current_period_end: '2026-04-01T00:00:00+00:00',
    next_invoice_at: '2026-04-01T00:00:00+00:00',
    auto_renew: true,
    is_scheduled_to_cancel: false,
    service_is_running: true,
    data_retention_days: 30,
  }
}

const ROWS = [
  subscription('01JFIRST', 'Starter', 'web-01', 9000),
  subscription('01JSECOND', 'Business', 'db-01', 12000),
]

const PAGE_META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch(rows = ROWS) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    const body = path.endsWith('/subscriptions') ? { data: rows, meta: PAGE_META } : null

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
        <SubscriptionsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('two subscriptions are tellable apart', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('names the plan, the product and the machine on each row', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('Starter')).toBeInTheDocument()
    expect(screen.getByText('Business')).toBeInTheDocument()
    expect(screen.getByText('web-01')).toBeInTheDocument()
    expect(screen.getByText('db-01')).toBeInTheDocument()
    expect(screen.getAllByText('Cloud VPS')).toHaveLength(2)

    // And each machine is a way into it, at the id the API resolved.
    expect(screen.getByRole('link', { name: 'web-01' })).toHaveAttribute('href', '/vps/vm-01JFIRST')
  })

  it('repeats which one is being ended inside the cancellation dialogue', async () => {
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()

    renderPage()

    const rows = await screen.findAllByRole('row')
    const second = rows.find((row) => within(row).queryByText('db-01') !== null)

    expect(second).toBeDefined()

    await user.click(within(second as HTMLElement).getByRole('button', { name: /^cancel$/i }))

    const dialog = await screen.findByRole('dialog')

    // The machine, in the dialogue, so the confirmation is about something.
    expect(within(dialog).getByText('db-01')).toBeInTheDocument()
    expect(within(dialog).getByText('Business')).toBeInTheDocument()
  })

  it('says a service is still being created rather than showing a made-up name', async () => {
    vi.stubGlobal('fetch', stubFetch([subscription('01JNEW', 'Starter', null, 9000)]))

    renderPage()

    expect(await screen.findByText(/being created/i)).toBeInTheDocument()
  })

  it('prints the subscription’s own price rather than a plan price', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    // 12.000 KWD is what the second agreement records; the catalogue's current
    // price for that plan never reaches this screen.
    expect(await screen.findByText(/12\.000/)).toBeInTheDocument()
    expect(screen.getByText(/9\.000/)).toBeInTheDocument()
  })
})
