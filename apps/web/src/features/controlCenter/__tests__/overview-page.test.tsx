import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { OverviewPage } from '@/features/controlCenter/OverviewPage'
import '@/i18n'

/*
 * The overview's one job: what needs a person is first, each item a link to
 * where it is dealt with, and a quiet estate says so rather than showing an
 * empty list.
 */

const OVERVIEW = {
  machines: { total: 3, by_classification: { do_not_touch: 2, configuration_allowed: 1 }, by_state: { registered: 2, managed: 1 } },
  providers: { total: 2, by_state: { enabled: 1, blocked: 1 }, by_readiness: { ready_for_production: 1, not_ready: 1 } },
  credentials: { by_state: { configured: 1, missing: 1 } },
  licences: { by_state: { active: 1 } },
  products: { by_state: { not_ready: 7 } },
  deployments: { by_state: { completed: 1, indeterminate: 1 } },
  sites: { datacenters: 1, racks: 2 },
  attention: { deployments_waiting: 1, providers_enabled_not_ready: 0, credentials_missing: 1, licences_expiring: 0, infrastructure_drift_open: 0, machines_never_classified: 2 },
}

function stubFetch(attention: Partial<typeof OVERVIEW.attention> = {}) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/infrastructure/overview')) {
      body = { data: { ...OVERVIEW, attention: { ...OVERVIEW.attention, ...attention } } }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({ ok: true, status: 200, statusText: '', headers: new Headers(), text: () => Promise.resolve(JSON.stringify(body)) } as Response)
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <OverviewPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('OverviewPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists what needs a person, with counts and links, and nothing that does not', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const attention = await screen.findByRole('list', { name: /needs a person/i })
    const items = within(attention).getAllByRole('listitem')
    expect(items).toHaveLength(3)

    const waiting = within(attention).getByRole('link', { name: /deployment\(s\) stopped without an answer/i })
    expect(waiting).toHaveAttribute('href', '/admin/control-center/deployments')
    expect(within(waiting).getByText('1')).toBeInTheDocument()

    expect(within(attention).queryByText(/licence\(s\) expiring/i)).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /^machines$/i })).toHaveAttribute('href', '/admin/control-center/machines')
  })

  it('says so when nothing needs a person', async () => {
    vi.stubGlobal('fetch', stubFetch({ deployments_waiting: 0, credentials_missing: 0, machines_never_classified: 0 }))
    renderPage()

    expect(await screen.findByText(/nothing is waiting for a person/i)).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: /needs a person/i })).not.toBeInTheDocument()
  })
})
