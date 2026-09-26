import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { AdminProvisioningPage } from '@/features/admin/AdminProvisioningPage'
import '@/i18n'

/*
 * Which of the jobs waiting for a person the page offers to adopt, and with
 * what (F-15).
 *
 * Adopt sends the reference the page prefilled. Where the job's current
 * finding is that a machine named otherwise holds the reserved identity, that
 * machine is a stranger's, and the page must not propose adopting it as the
 * customer's — whatever the server would say to it.
 */

const BASE = {
  kind: 'create_vps',
  status: 'needs_review',
  customer_id: '01JCUSTOMER',
  failure_class: 'permanent',
  attempts: 1,
  reserved_cluster_id: '01JCLUSTER',
  reserved_provider_nodes: ['pve-01'],
  reserved_provider_hostnames: ['web-01'],
  provider_reference: null,
  created_at: '2026-09-01T00:00:00+00:00',
}

const STRANGERS = {
  ...BASE,
  id: '01JSTRANGERS',
  error_code: 'vps.create_identity_taken',
  error_reason: 'named_otherwise',
  last_error: 'Somebody else\'s machine, named someone-elses-box, holds provider identity 43542.',
  reserved_provider_id: '43542',
}

const ITS_OWN = {
  ...BASE,
  id: '01JITSOWN',
  error_code: 'vps.create_found_its_own_build',
  error_reason: 'named_as_called',
  last_error: 'An earlier attempt built this machine at provider identity 51234.',
  reserved_provider_id: '51234',
}

const META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch() {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = (url.split('?')[0] ?? url).replace(/^https?:\/\/[^/]+/, '')

    let body: unknown

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/api/admin/provisioning/needs-review') || path.endsWith('/api/admin/provisioning/jobs')) {
      body = { data: [STRANGERS, ITS_OWN], meta: META }
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
        <AdminProvisioningPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

async function reviewRowOf(id: string): Promise<HTMLElement> {
  const text = await screen.findByText(new RegExp(`^${id} ·`))
  const row = text.closest('li')

  if (row === null) throw new Error(`No review row holds ${id}`)

  return row
}

describe('AdminProvisioningPage review list', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('does not offer to adopt the machine a stranger holds at the reserved identity', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const row = await reviewRowOf(STRANGERS.id)

    expect(within(row).queryByRole('button', { name: /adopt what it built/i })).not.toBeInTheDocument()
    // The way out that finding licenses is still there.
    expect(within(row).getByRole('button', { name: /move to a new identity/i })).toBeInTheDocument()
  })

  it('offers to adopt the reserved identity where the job found its own build there', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const row = await reviewRowOf(ITS_OWN.id)

    await userEvent.click(within(row).getByRole('button', { name: /adopt what it built/i }))

    expect(await screen.findByText(/records machine 51234 as this job's build/i)).toBeInTheDocument()
  })
})
