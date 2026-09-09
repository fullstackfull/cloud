import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { PlansPage } from '@/features/controlCenter/PlansPage'
import '@/i18n'

/*
 * The plans screen without a browser: the run is not offered without an
 * approval, the approval dialogue shows the fingerprint it is for, and the
 * override fields are exactly the keys the profile's components declare.
 */

const SERVER = {
  id: '01JSRV',
  name: 'redis-01',
  environment: 'staging',
  state: 'profiled',
  safety: { classification: 'configuration_allowed', allow_reimage: false, reason: 'Lab.', changed_at: null, permits: { read: true, configure: true, reimage: false } },
  location: { datacenter_id: null, rack_id: null, rack_unit: null, height_units: null },
  hardware: { vendor: null, model: null, serial: null, asset_tag: null, operating_system: null },
  connection: { state: 'not_tested', blocker: null, management_address: 'fake://connected', bmc_address: null, credential: null, last_tested_at: null },
  last_discovery_at: null,
  last_deployment_at: null,
  last_verification_at: null,
  notes: null,
  created_at: null,
}

const PROFILES = [{
  key: 'redis', name: 'Redis', intended_role: 'redis', playbook: 'redis.yml', description: null,
  components: [
    { key: 'common', name: 'Base', category: 'base', ansible_role: 'common', risk: 'low', requires_reboot: false, verification: null, accepts: ['timezone'], description: null },
    { key: 'redis', name: 'Redis', category: 'cache', ansible_role: 'redis', risk: 'moderate', requires_reboot: false, verification: 'service:redis-server', accepts: ['maxmemory'], description: null },
  ],
}]

const PLAN = {
  id: '01JPLAN',
  server_id: SERVER.id,
  fingerprint: 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
  changes: [
    { component: 'common', action: 'install', role: 'common', risk: 'low', requires_reboot: false, configuration: {}, reason: 'Never verified.' },
    { component: 'redis', action: 'install', role: 'redis', risk: 'moderate', requires_reboot: false, configuration: { maxmemory: '1gb' }, reason: 'Never verified.' },
  ],
  unchanged: [],
  blockers: [],
  risk: 'moderate',
  required_safety_class: 'configuration_allowed',
  requires_reboot: false,
  is_destructive: false,
  is_applicable: true,
  approval: null,
  planned_by: '01JUSER',
  planned_at: '2026-09-09T08:00:00+00:00',
  refreshed_at: '2026-09-09T08:00:00+00:00',
}

function stubFetch(onApprove?: (body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/infrastructure/servers')) {
      body = { data: [SERVER], meta: { page: 1, per_page: 25, total: 1, last_page: 1, max_per_page: 100 } }
    } else if (path.endsWith('/infrastructure/profiles')) {
      body = { data: PROFILES }
    } else if (path.endsWith('/desired-state')) {
      body = { data: { id: '01JDS', server_id: SERVER.id, profile: 'redis', profile_name: 'Redis', overrides: { 'redis.maxmemory': '1gb' }, assigned_at: null } }
    } else if (path.endsWith('/approve')) {
      onApprove?.(parsed)
      body = { data: { ...PLAN, approval: { id: '01JAPPR', approved_fingerprint: PLAN.fingerprint, approved_at: '2026-09-09T09:00:00+00:00', approved_by: '01JOTHER', reason: parsed?.reason } } }
    } else if (path.endsWith('/plan')) {
      body = { data: PLAN }
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
        <PlansPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('PlansPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers override fields only for keys the profile declares, and no run without an approval', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText(/^machine$/i), SERVER.id)

    const form = await screen.findByRole('form', { name: /assign profile/i })
    expect(within(form).getByLabelText('common.timezone')).toBeInTheDocument()
    expect(within(form).getByLabelText('redis.maxmemory')).toHaveValue('1gb')
    expect(within(form).queryByLabelText('redis.bind_address')).not.toBeInTheDocument()

    expect(await screen.findByText(/not approved/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /run now/i })).toBeDisabled()
    expect(screen.getByText(/risk: moderate/i)).toBeInTheDocument()
  })

  it('shows the fingerprint in the approval dialogue and sends the reason', async () => {
    const onApprove = vi.fn()
    vi.stubGlobal('fetch', stubFetch(onApprove))
    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText(/^machine$/i), SERVER.id)
    await userEvent.click(await screen.findByRole('button', { name: /^approve$/i }))

    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText(PLAN.fingerprint)).toBeInTheDocument()
    const confirm = within(dialog).getByRole('button', { name: /^approve$/i })
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/why\?/i), 'Reviewed the diff.')
    await userEvent.click(confirm)

    await waitFor(() => { expect(onApprove).toHaveBeenCalledWith({ reason: 'Reviewed the diff.' }); })
  })
})
