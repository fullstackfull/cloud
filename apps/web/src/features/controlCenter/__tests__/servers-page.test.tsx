import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ServersPage } from '@/features/controlCenter/ServersPage'
import '@/i18n'

/*
 * The machine screen's contract with the operator, without a browser.
 *
 * Two things must be true whatever the API does: a do-not-touch machine has
 * no working "test" or "discover" control, and the destructive rung of the
 * classification ladder cannot be reached without the machine's name typed.
 * Both are enforced by the API too; the screen exists so the refusal is
 * seen before the request, not after.
 */

const UNTOUCHED = {
  id: '01JSRVUNTOUCHED',
  name: 'node-02',
  environment: 'staging',
  state: 'registered',
  safety: { classification: 'do_not_touch', allow_reimage: false, reason: null, changed_at: null, permits: { read: false, configure: false, reimage: false } },
  location: { datacenter_id: null, rack_id: null, rack_unit: null, height_units: null },
  hardware: { vendor: null, model: null, serial: null, asset_tag: null, operating_system: null },
  connection: { state: 'not_tested', blocker: null, management_address: '10.66.0.2', bmc_address: null, credential: null, last_tested_at: null },
  last_discovery_at: null,
  last_deployment_at: null,
  last_verification_at: null,
  notes: null,
  created_at: '2026-09-01T00:00:00+00:00',
}

const CONFIGURABLE = {
  ...UNTOUCHED,
  id: '01JSRVCONFIG',
  name: 'node-01',
  state: 'discovered',
  safety: { classification: 'configuration_allowed', allow_reimage: false, reason: 'Lab machine.', changed_at: '2026-09-02T00:00:00+00:00', permits: { read: true, configure: true, reimage: false } },
  hardware: { vendor: 'Fabrikam', model: 'FX-2200', serial: 'FX-1', asset_tag: null, operating_system: null },
  connection: { state: 'connected', blocker: null, management_address: 'fake://connected', bmc_address: 'fake://connected', credential: null, last_tested_at: '2026-09-02T00:00:00+00:00' },
  last_discovery_at: '2026-09-02T00:00:00+00:00',
}

const PAGE_META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch(onClassify?: (body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/classify')) {
      onClassify?.(parsed)
      body = { data: { ...CONFIGURABLE, safety: { ...CONFIGURABLE.safety, classification: parsed?.safety_class, reason: parsed?.reason } } }
    } else if (path.endsWith('/facts')) {
      body = { data: [{ key: 'bmc.firmware', value: '2.81', source: 'discovered', observed_at: '2026-09-02T00:00:00+00:00', superseded_at: null }] }
    } else if (path.endsWith('/credentials')) {
      body = { data: [], meta: { ...PAGE_META, total: 0 } }
    } else if (path.endsWith('/infrastructure/servers')) {
      body = { data: [CONFIGURABLE, UNTOUCHED], meta: PAGE_META }
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
        <ServersPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('ServersPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows classification and connection as words, and says a machine was never classified', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const untouched = await screen.findByRole('row', { name: /node-02/ })
    expect(within(untouched).getByText(/^do not touch$/i)).toBeInTheDocument()
    expect(within(untouched).getByText(/^not tested$/i)).toBeInTheDocument()
    expect(screen.queryByText(/do_not_touch/)).not.toBeInTheDocument()

    await userEvent.click(within(untouched).getByRole('button', { name: /^open$/i }))
    expect(screen.getByText(/never classified/i)).toBeInTheDocument()
  })

  it('offers no working test or discovery on a do-not-touch machine', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const untouched = await screen.findByRole('row', { name: /node-02/ })
    await userEvent.click(within(untouched).getByRole('button', { name: /^open$/i }))

    expect(screen.getByRole('button', { name: /test connection/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /^discover$/i })).toBeDisabled()
    expect(screen.getByText(/may not be connected to, even to test/i)).toBeInTheDocument()
  })

  it('only offers the next rung up, and demands the name typed for reimage-allowed', async () => {
    const onClassify = vi.fn()
    vi.stubGlobal('fetch', stubFetch(onClassify))
    renderPage()

    const row = await screen.findByRole('row', { name: /node-01/ })
    await userEvent.click(within(row).getByRole('button', { name: /^open$/i }))
    await userEvent.click(screen.getByRole('button', { name: /^classify$/i }))

    const dialog = screen.getByRole('dialog')
    const select = within(dialog).getByLabelText(/new classification/i)
    const offered = within(select).getAllByRole('option').map((option) => option.textContent)
    // Up one (reimage allowed) and down any (discovery only, do not touch); never the current rung.
    expect(offered).toEqual(['—', 'Do not touch', 'Discovery only', 'Reimage allowed'])

    await userEvent.selectOptions(select, 'reimage_allowed')
    await userEvent.type(within(dialog).getByLabelText(/why\?/i), 'Lab machine, wiped weekly.')

    const confirm = within(dialog).getByRole('button', { name: /^classify$/i })
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/type .* to confirm/i), 'node-01')
    await userEvent.click(confirm)

    await waitFor(() => {
      expect(onClassify).toHaveBeenCalledWith({ safety_class: 'reimage_allowed', reason: 'Lab machine, wiped weekly.', confirm_name: 'node-01' })
    })
  })

  it('shows discovered facts with their source', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const row = await screen.findByRole('row', { name: /node-01/ })
    await userEvent.click(within(row).getByRole('button', { name: /^open$/i }))

    expect(await screen.findByText('bmc.firmware')).toBeInTheDocument()
    expect(screen.getByText(/2\.81/)).toBeInTheDocument()
    expect(screen.getByText(/\(discovered\)/)).toBeInTheDocument()
  })
})
