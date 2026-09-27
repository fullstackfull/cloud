import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { AdminInfrastructurePage } from '@/features/admin/AdminInfrastructurePage'
import '@/i18n'

/*
 * A hosting node whose account listing the reconciliation sweep could not
 * read is not compared with the platform's records at all. It used to be
 * skipped without a trace; the API now carries the refusal, and the node
 * list is where an operator sees it.
 */

const NODE = {
  slug: 'node-a',
  panel: 'directadmin',
  panel_version: null,
  status: 'active',
  accepts_new_accounts: true,
  panel_licensed: true,
  licence_status: 'valid',
  account_count: 3,
  max_accounts: 100,
  disk_used_mib: null,
  disk_total_mib: null,
  load_average: null,
  last_synced_at: null,
}

const REFUSED = {
  ...NODE,
  id: '01JREFUSED',
  hostname: 'refused.lynomia.test',
  reconciled_at: null,
  reconcile_attempted_at: '2026-09-27T06:00:00+00:00',
  reconcile_error: 'an element of the account listing is not one account name',
}

const READ = {
  ...NODE,
  id: '01JREAD',
  hostname: 'read.lynomia.test',
  reconciled_at: '2026-09-27T06:00:00+00:00',
  reconcile_attempted_at: '2026-09-27T06:00:00+00:00',
  reconcile_error: null,
}

const META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch() {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = (url.split('?')[0] ?? url).replace(/^https?:\/\/[^/]+/, '')

    let body: unknown

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/api/admin/infrastructure/hosting-nodes')) {
      body = { data: [REFUSED, READ], meta: META }
    } else if (path.endsWith('/api/admin/infrastructure/nodes')) {
      body = { data: [], meta: { ...META, total: 0 } }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      headers: new Headers(),
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response)
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <AdminInfrastructurePage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

async function rowOf(hostname: string): Promise<HTMLElement> {
  const cell = await screen.findByText(hostname)
  const row = cell.closest('tr')

  if (row === null) throw new Error(`No row holds ${hostname}`)

  return row
}

describe('AdminInfrastructurePage hosting nodes', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('marks a node whose accounts could not be compared, with the reason', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const refused = await rowOf(REFUSED.hostname)
    const badge = within(refused).getByText(/accounts not compared/i)

    expect(badge.closest('[title]')).toHaveAttribute('title', REFUSED.reconcile_error)
    expect(within(await rowOf(READ.hostname)).queryByText(/accounts not compared/i)).not.toBeInTheDocument()
  })
})
