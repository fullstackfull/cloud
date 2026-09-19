import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { VpsPage } from '@/features/infrastructure/VpsPage'
import '@/i18n'

/**
 * The VPS list, which since Wave 3 is an index.
 *
 * The specification column used to read "vCPU · NaN GiB · GiB" on every row,
 * because the page read fields the API nests under `resources`. That is still
 * asserted here, and so is the row's way in.
 *
 * The destructive controls moved to the machine's own page — see
 * vps-detail-page.test.tsx, which carries Wave 0's guarantees about them: the
 * force-off confirmation, one request per decision, and a stranded machine's
 * controls being off with the reason said out loud.
 */

const MACHINE = {
  id: '01JVM',
  service_id: '01JSERVICE',
  hostname: 'web-kw-01',
  service_status: 'active',
  power_state: 'running',
  resources: { vcpu: 2, memory_mib: 4096, disk_gib: 40 },
  os_family: 'debian',
  os_version: '12',
  addresses: [{ address: '198.51.100.24', ip_version: 4, is_primary: true }],
  is_operable: true,
  actions: { power: true, reinstall: true, blocked_reason: null },
  reinstall: null,
  created_at: '2026-03-01T00:00:00+00:00',
}

const STRANDED = {
  ...MACHINE,
  id: '01JVMSTRANDED',
  service_id: '01JSERVICE2',
  hostname: 'web-kw-02',
  resources: { vcpu: 4, memory_mib: 8192, disk_gib: 80 },
  actions: { power: false, reinstall: false, blocked_reason: 'operation_needs_review' },
  reinstall: {
    id: '01JREINSTALL',
    state: 'indeterminate',
    in_flight: false,
    needs_attention: true,
    data_destroyed: true,
    requested_at: '2026-03-01T00:00:00+00:00',
    completed_at: null,
  },
}

const PAGE_META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch(onPower?: (headers: Headers, body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/power')) {
      onPower?.(new Headers(init?.headers), JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { kind: 'stop', status: 'queued' } }
    } else if (path.endsWith('/vps')) {
      body = { data: [MACHINE, STRANDED], meta: PAGE_META }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: true,
      status: 202,
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
        <VpsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

function row(hostname: string) {
  return screen.getByRole('row', { name: new RegExp(hostname) })
}

describe('the VPS list', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('renders the specification from the nested resources the API sends, never NaN', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('2 vCPU · 4 GiB · 40 GiB')).toBeInTheDocument()
    expect(screen.getByText('4 vCPU · 8 GiB · 80 GiB')).toBeInTheDocument()
    expect(screen.queryByText(/NaN/)).not.toBeInTheDocument()
    expect(screen.queryByText(/undefined/)).not.toBeInTheDocument()
  })

  it('names each machine as a link to it, and offers the one control a list needs', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()
    await screen.findByText('2 vCPU · 4 GiB · 40 GiB')

    // The hostname is the way in. Before Wave 3 there was no page to go to.
    expect(screen.getByRole('link', { name: 'web-kw-01' })).toHaveAttribute('href', '/vps/01JVM')

    const healthy = row('web-kw-01')

    // Reboot is the one power control a list keeps: the rest, and everything
    // destructive, are on the machine's own page.
    expect(within(healthy).getByRole('button', { name: /^reboot$/i })).toBeEnabled()

    for (const name of [/^start$/i, /^shut down$/i, /^force off$/i, /^reinstall$/i]) {
      expect(within(healthy).queryByRole('button', { name })).not.toBeInTheDocument()
    }
  })

  it('sends every other power action straight away with the key in the header', async () => {
    const powered = vi.fn()
    vi.stubGlobal('fetch', stubFetch(powered))
    const user = userEvent.setup()

    renderPage()
    await screen.findByText('2 vCPU · 4 GiB · 40 GiB')

    await user.click(within(row('web-kw-01')).getByRole('button', { name: /^reboot$/i }))

    await waitFor(() => {
      expect(powered).toHaveBeenCalledTimes(1)
    })
    const [headers, body] = powered.mock.calls[0] as [Headers, Record<string, unknown>]
    expect(body).toEqual({ action: 'reboot' })
    expect(headers.get('Idempotency-Key')).not.toBeNull()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('disables the control on a machine the API says it would refuse, and says why', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()
    await screen.findByText('2 vCPU · 4 GiB · 40 GiB')

    const stranded = row('web-kw-02')

    expect(within(stranded).getByRole('button', { name: /^reboot$/i })).toBeDisabled()
    expect(within(stranded).getByText(/our team is looking at it\. controls stay off/i)).toBeInTheDocument()

    // And the healthy neighbour is untouched.
    expect(within(row('web-kw-01')).getByRole('button', { name: /^reboot$/i })).toBeEnabled()
  })
})
