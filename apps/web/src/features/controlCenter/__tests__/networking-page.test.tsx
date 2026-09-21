import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { NetworkingPage } from '@/features/controlCenter/NetworkingPage'
import '@/i18n'

/*
 * The screen that turns an empty deployment into a configurable one, and the
 * two things it must never let an operator get wrong: that recording a cluster
 * is not reaching it, and that a management address pool is not capacity.
 */

const DATACENTER = { id: '01JDC', slug: 'kw-dc-1', name: 'Kuwait DC 1', facility: null, region_id: '01JREGION', region: 'Kuwait', is_active: true, racks: 1, machines: 0 }

const CLUSTER = {
  id: '01JCLUSTER',
  slug: 'kw-pve-1',
  name: 'Kuwait Proxmox 1',
  driver: 'proxmox',
  status: 'active',
  datacenter: 'kw-dc-1',
  datacenter_id: '01JDC',
  api_endpoint: 'https://pve.mgmt.example:8006',
  verify_tls: true,
  credentials_reference: 'pve-token',
  accepts_placement: true,
  last_synced_at: null,
  nodes: 0,
  templates: 0,
  version: 'abc123',
}

const CUSTOMER_POOL = { id: '01JPOOLPUB', slug: 'kw-public-v4', name: 'Kuwait Public', scope: 'public', ip_version: 4, customer_allocatable: true, is_active: true }
const MANAGEMENT_POOL = { id: '01JPOOLMGMT', slug: 'kw-mgmt-v4', name: 'Kuwait Management', scope: 'management', ip_version: 4, customer_allocatable: false, is_active: true }

function stubFetch(capture?: (path: string, body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = (url.split('?')[0] ?? url).replace(/^https?:\/\/[^/]+/, '')
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null
    let status = 200

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (init?.method === 'POST') {
      capture?.(path, parsed)
      body = { data: { id: '01JNEW' } }
      status = 201
    } else if (path.endsWith('/infrastructure/clusters')) {
      body = { data: [CLUSTER] }
    } else if (path.endsWith('/infrastructure/networks')) {
      body = { data: [] }
    } else if (path.endsWith('/infrastructure/ip-pools')) {
      body = { data: [CUSTOMER_POOL, MANAGEMENT_POOL] }
    } else if (path.includes('/subnets')) {
      body = { data: [] }
    } else if (path.endsWith('/infrastructure/datacenters')) {
      body = { data: [DATACENTER] }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: status < 400,
      status,
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
        <NetworkingPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('NetworkingPage', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('says a cluster has never been contacted rather than implying it answers', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const cluster = await screen.findByRole('listitem', { name: /Kuwait Proxmox 1/i })

    expect(within(cluster).getByText(/not contacted yet/i)).toBeInTheDocument()
  })

  it('tells a management pool apart from customer capacity', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const management = await screen.findByRole('listitem', { name: /kw-mgmt-v4/i })
    const customer = await screen.findByRole('listitem', { name: /kw-public-v4/i })

    expect(within(management).getByText(/never customer capacity/i)).toBeInTheDocument()
    expect(within(customer).getByText(/customer allocatable/i)).toBeInTheDocument()
  })

  it('warns before a management pool is created, and says the scope is permanent', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /register an address pool/i }))

    const form = await screen.findByRole('form', { name: /register an address pool/i })

    // The default is a customer pool, and the warning is about permanence.
    expect(within(form).getByText(/fixed once the pool exists/i)).toBeInTheDocument()

    await userEvent.selectOptions(within(form).getByLabelText(/scope/i), 'management')

    expect(within(form).getByText(/reaches the hypervisor and bmc control planes/i)).toBeInTheDocument()
  })

  it('refuses to offer customer-facing for a control-plane segment', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /register a network/i }))

    const form = await screen.findByRole('form', { name: /register a network/i })

    expect(within(form).getByLabelText(/customer workloads may use this segment/i)).toBeInTheDocument()

    await userEvent.selectOptions(within(form).getByLabelText(/purpose/i), 'management')

    expect(within(form).queryByLabelText(/customer workloads may use this segment/i)).not.toBeInTheDocument()
    expect(within(form).getByText(/never carry customer workloads/i)).toBeInTheDocument()
  })

  it('sends what the operator typed, and no secret', async () => {
    const sent: { path: string; body: unknown }[] = []
    vi.stubGlobal('fetch', stubFetch((path, body) => { sent.push({ path, body }) }))
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /register a cluster/i }))

    const form = await screen.findByRole('form', { name: /register a cluster/i })

    await userEvent.selectOptions(within(form).getByLabelText(/datacenter/i), '01JDC')
    await userEvent.type(within(form).getByLabelText(/logical id/i), 'kw-pve-2')
    await userEvent.type(within(form).getByLabelText(/display name/i), 'Kuwait Proxmox 2')
    await userEvent.type(within(form).getByLabelText(/credential reference/i), 'pve-token')

    await userEvent.click(within(form).getByRole('button', { name: /register a cluster/i }))

    await waitFor(() => { expect(sent).toHaveLength(1) })

    const body = sent[0]?.body as Record<string, unknown>

    expect(body.slug).toBe('kw-pve-2')
    expect(body.datacenter_id).toBe('01JDC')
    // A reference, and nothing that could be a secret.
    expect(body.credentials_reference).toBe('pve-token')
    expect(Object.keys(body)).not.toContain('password')
    expect(Object.keys(body)).not.toContain('token')
  })

  it('tells an operator what is missing before a VPS can be placed', async () => {
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL): Promise<Response> => {
      const url = input instanceof Request ? input.url : String(input)
      const path = (url.split('?')[0] ?? url).replace(/^https?:\/\/[^/]+/, '')
      const body = path.endsWith('/sanctum/csrf-cookie') ? null : { data: [] }

      return Promise.resolve({
        ok: true, status: 200, statusText: '', headers: new Headers(),
        text: () => Promise.resolve(JSON.stringify(body)),
      } as Response)
    }))

    renderPage()

    expect(await screen.findByText(/cannot be placed until there is one/i)).toBeInTheDocument()
    expect(await screen.findByText(/a customer may be given an address from/i)).toBeInTheDocument()
  })
})
