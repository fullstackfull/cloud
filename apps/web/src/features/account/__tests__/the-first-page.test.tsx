import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { DashboardPage } from '@/features/account/DashboardPage'
import '@/i18n'
import type { AccountOverview } from '@/lib/types'

/**
 * AR-6 and BD-4: the dashboard answers what needs doing, and says it first.
 *
 * The claims under test are the ones a screenshot cannot settle:
 *
 *  - attention comes before the counts, in the order the server sent, and each
 *    row links to the exact resource rather than to its index;
 *  - two currencies owing are two amounts and never a sum;
 *  - a renewal with no authoritative price says so instead of quoting one;
 *  - a brand-new account gets a way forward, not six empty cards;
 *  - a read that fails says so, where the old page rendered nothing at all.
 */

function overview(overrides: Partial<AccountOverview> = {}): AccountOverview {
  return {
    attention: [],
    services: { total: 0, by_state: {} },
    billing: { due: [] },
    renewals: [],
    unread_notifications: 0,
    recent: { services: [], activity: [] },
    ...overrides,
  }
}

function stub(responses: Record<string, { status: number; body?: unknown }>) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const match = Object.keys(responses).find((candidate) => path.endsWith(candidate))
    const route = match === undefined ? undefined : responses[match]

    if (route === undefined) throw new Error(`Unstubbed request: ${url}`)

    return Promise.resolve({
      ok: route.status >= 200 && route.status < 300,
      status: route.status,
      statusText: '',
      text: () => Promise.resolve(route.body === undefined ? '' : JSON.stringify(route.body)),
    } as Response)
  })
}

const USER = {
  data: {
    id: '01JUSER',
    name: 'Noura',
    email: 'noura@lynomia.test',
    email_verified: true,
    locale: 'en',
    timezone: 'Asia/Kuwait',
    phone: null,
    two_factor_enabled: true,
    last_login_at: null,
    created_at: null,
    permissions: [],
    customers: [],
  },
}

async function mount(body: unknown, status = 200) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  vi.stubGlobal(
    'fetch',
    stub({
      '/me': { status: 200, body: USER },
      '/me/overview': { status, body },
      '/notifications/unread-count': { status: 200, body: { data: { unread: 0 } } },
    }),
  )

  const view = render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )

  // The dashboard is one read; one tick is enough for it to land.
  await screen.findByRole('heading', { level: 1 })

  return view
}

describe('the first page of the portal', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('puts what needs attention above what the account holds', async () => {
    await mount({
      data: overview({
        attention: [
          {
            id: 'invoice:01JINV',
            kind: 'attention.invoice.overdue',
            severity: 'critical',
            occurred_at: new Date(Date.now() - 3 * 86_400_000).toISOString(),
            resource: { kind: 'invoice', id: '01JINV', identity: 'INV-000042' },
            reference: 'INV-000042',
          },
          {
            id: 'operation:01JOP',
            kind: 'attention.operation.needsReview',
            severity: 'warning',
            occurred_at: new Date(Date.now() - 3_600_000).toISOString(),
            resource: { kind: 'vps', id: '01JVM', identity: 'web-01' },
            reference: null,
          },
        ],
        services: { total: 3, by_state: { active: 3 } },
      }),
    })

    const attention = await screen.findByRole('heading', { name: 'Needs your attention' })
    const held = screen.getByRole('heading', { name: 'What you have' })

    /*
     * Asserted by document order rather than by pixels. The ordering claim is
     * about which question the page answers first, and a test that measured
     * coordinates would pass a layout that put the counts first in a
     * two-column grid.
     */
    expect(attention.compareDocumentPosition(held)).toBe(Node.DOCUMENT_POSITION_FOLLOWING)

    // The server's order survives: overdue money before a stopped rebuild.
    const rows = screen.getAllByRole('listitem')
    expect(rows[0]?.textContent).toContain('An invoice is overdue')
    expect(rows[1]?.textContent).toContain('Work stopped and we are looking at it')
  })

  it('links an attention row to the exact thing, not to its list', async () => {
    await mount({
      data: overview({
        attention: [
          {
            id: 'invoice:01JINV',
            kind: 'attention.invoice.overdue',
            severity: 'critical',
            occurred_at: new Date().toISOString(),
            resource: { kind: 'invoice', id: '01JINV', identity: 'INV-000042' },
            reference: 'INV-000042',
          },
        ],
        services: { total: 1, by_state: { active: 1 } },
      }),
    })

    const open = await screen.findByRole('link', { name: 'Open' })

    // §36. `/invoices` would make the customer find the overdue one again,
    // which is the work the row exists to save.
    expect(open).toHaveAttribute('href', '/invoices/01JINV')
  })

  it('shows two currencies as two amounts and never adds them up', async () => {
    await mount({
      data: overview({
        services: { total: 2, by_state: { active: 2 } },
        billing: {
          due: [
            {
              invoices: 2,
              amount: { minor_units: 10_000, currency: 'KWD', amount: '10.000' },
            },
            {
              invoices: 1,
              amount: { minor_units: 2_000, currency: 'USD', amount: '20.00' },
            },
          ],
        },
      }),
    })

    const owed = (await screen.findByRole('heading', { name: 'What you owe' })).closest('section')

    expect(owed).not.toBeNull()
    expect(within(owed as HTMLElement).getByText(/KWD/)).toBeInTheDocument()
    expect(within(owed as HTMLElement).getByText(/USD/)).toBeInTheDocument()

    /*
     * The number that must not exist. 10 KWD and 20 USD do not add up to 30 of
     * anything, and a dashboard that printed a total would be wrong in a way a
     * customer would believe.
     */
    expect(within(owed as HTMLElement).queryByText(/\b30\b/)).not.toBeInTheDocument()
  })

  it('says a renewal is priced at renewal rather than quoting a guess', async () => {
    await mount({
      data: overview({
        services: { total: 1, by_state: { active: 1 } },
        renewals: [
          {
            kind: 'domain',
            resource: { kind: 'domain', id: '01JDOM', identity: 'lynomia.test' },
            at: '2027-03-09',
            amount: null,
          },
        ],
      }),
    })

    expect(await screen.findByText('Priced at renewal')).toBeInTheDocument()

    // And the date is a calendar date, rendered as the day it names.
    expect(screen.getByText(/09/)).toBeInTheDocument()
  })

  it('gives a brand new account one sentence and one way forward', async () => {
    await mount({ data: overview() })

    expect(await screen.findByRole('heading', { name: 'Welcome to Lynomia' })).toBeInTheDocument()

    // Not six empty cards, which read as a broken dashboard.
    expect(screen.queryByRole('heading', { name: 'What you owe' })).not.toBeInTheDocument()

    expect(screen.getByRole('link', { name: 'See the catalogue' })).toHaveAttribute(
      'href',
      '/catalogue',
    )
  })

  it('says so when the dashboard cannot be read', async () => {
    await mount(
      { error: { code: 'server.error', message: 'Something went wrong on our side.' } },
      500,
    )

    /*
     * AS-15. The page this replaced returned `null` for a missing read, which
     * is the same pixels as a working dashboard with nothing on it — so a
     * customer could not tell a quiet account from a broken portal.
     */
    expect(await screen.findByRole('alert')).toBeInTheDocument()
  })
})
