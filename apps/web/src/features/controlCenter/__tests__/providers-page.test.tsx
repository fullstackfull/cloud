import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ProvidersPage } from '@/features/controlCenter/ProvidersPage'
import '@/i18n'

/*
 * The provider screen without a browser: the readiness column names a
 * blocker and what to do about it in words, "Enable" is not pressable until
 * the API would accept it, and a disable cannot be sent without a reason.
 */

const BLOCKED = {
  id: '01JPRVBLOCKED',
  name: 'dns-primary',
  category: 'dns',
  driver: 'fake',
  environment: 'staging',
  state: 'draft',
  is_serving: false,
  can_test: true,
  endpoint: 'fake://connected',
  connection: { state: 'not_tested', reached: false, usable: false, detail: null, last_tested_at: null, last_discovery_at: null },
  readiness: { state: 'not_ready', blocker: 'blocked_credentials', next_action: 'controlCenter.guidance.credentials' },
  credential: null,
  licence: null,
  server: null,
  capabilities: [],
  enabled_at: null,
  disabled_at: null,
  disabled_reason: null,
  created_at: '2026-09-01T00:00:00+00:00',
}

const READY = {
  ...BLOCKED,
  id: '01JPRVREADY',
  name: 'dns-secondary',
  state: 'ready',
  connection: { state: 'connected', reached: true, usable: true, detail: null, last_tested_at: '2026-09-02T00:00:00+00:00', last_discovery_at: '2026-09-02T00:00:00+00:00' },
  readiness: { state: 'ready_for_production', blocker: null, next_action: null },
  credential: { id: '01JCRED', credential_name: 'dns-key', credential_state: 'valid', credential_environment: 'staging' },
  capabilities: [{ capability: 'dns.zone.create', capability_state: 'supported', observed_at: '2026-09-02T00:00:00+00:00' }],
}

const ENABLED = { ...READY, id: '01JPRVENABLED', name: 'dns-live', state: 'enabled', is_serving: true, enabled_at: '2026-09-03T00:00:00+00:00' }

const PAGE_META = { page: 1, per_page: 25, total: 3, last_page: 1, max_per_page: 100 }

function stubFetch(onDisable?: (body: unknown) => void, onEnable?: () => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/disable')) {
      onDisable?.(parsed)
      body = { data: { ...ENABLED, state: 'disabled', is_serving: false, disabled_reason: parsed?.reason } }
    } else if (path.endsWith('/enable')) {
      onEnable?.()
      body = { data: { ...READY, state: 'enabled', is_serving: true } }
    } else if (path.endsWith('/credentials') || path.endsWith('/licences')) {
      body = { data: [], meta: { ...PAGE_META, total: 0 } }
    } else if (path.endsWith('/providers')) {
      body = { data: [BLOCKED, READY, ENABLED], meta: PAGE_META }
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
        <ProvidersPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('ProvidersPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('names the blocker and the next action in words', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const blocked = await screen.findByRole('row', { name: /dns-primary/ })
    expect(within(blocked).getByText(/^not ready$/i)).toBeInTheDocument()
    expect(within(blocked).getByText(/blocked: credentials/i)).toBeInTheDocument()
    expect(within(blocked).getByText(/attach a credential the controller holds/i)).toBeInTheDocument()
    expect(screen.queryByText(/controlCenter\.guidance/)).not.toBeInTheDocument()
  })

  it('does not offer to enable a provider that is not ready for production', async () => {
    const onEnable = vi.fn()
    vi.stubGlobal('fetch', stubFetch(undefined, onEnable))
    renderPage()

    const blocked = await screen.findByRole('row', { name: /dns-primary/ })
    await userEvent.click(within(blocked).getByRole('button', { name: /^open$/i }))

    const enable = screen.getByRole('button', { name: /^enable$/i })
    expect(enable).toBeDisabled()
    await userEvent.click(enable)
    expect(onEnable).not.toHaveBeenCalled()
  })

  it('enables a ready provider and shows what it can do', async () => {
    const onEnable = vi.fn()
    vi.stubGlobal('fetch', stubFetch(undefined, onEnable))
    renderPage()

    const ready = await screen.findByRole('row', { name: /dns-secondary/ })
    await userEvent.click(within(ready).getByRole('button', { name: /^open$/i }))

    expect(screen.getByText('dns.zone.create')).toBeInTheDocument()
    expect(screen.getByText(/^supported$/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /^enable$/i }))
    await waitFor(() => { expect(onEnable).toHaveBeenCalled(); })
  })

  it('will not disable without a reason', async () => {
    const onDisable = vi.fn()
    vi.stubGlobal('fetch', stubFetch(onDisable))
    renderPage()

    const live = await screen.findByRole('row', { name: /dns-live/ })
    expect(within(live).getByText(/^serving$/i)).toBeInTheDocument()
    await userEvent.click(within(live).getByRole('button', { name: /^open$/i }))
    await userEvent.click(screen.getByRole('button', { name: /^disable$/i }))

    const dialog = screen.getByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^disable$/i })
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/why\?/i), 'Vendor maintenance window.')
    await userEvent.click(confirm)

    await waitFor(() => { expect(onDisable).toHaveBeenCalledWith({ reason: 'Vendor maintenance window.' }); })
  })
})
