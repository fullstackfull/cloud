import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { PlansPage } from '@/features/controlCenter/PlansPage'
import '@/i18n'

/**
 * F-21's structural twin, on the operator surface.
 *
 * The Compute-plan button was gated on `desired.data?.data === null`, and
 * `undefined === null` is false — so while the desired-state read was pending,
 * or after it failed, the button was live against a desired state the page
 * could not read, and pressing it sent the POST. Only a desired state the
 * server had actually answered as `null` was caught. It is F-21's defect with
 * F-21's cause: a strict comparison against a sentinel, evaluated on a value
 * that can be absent.
 *
 * Lesser in effect than registration, because the card above renders
 * `<LoadFailure>` on an error and the operator is not left silent — which is
 * precisely what `RegisterPage` lacked. The POST is recorded rather than
 * inferred from the attribute.
 */

type Read = { kind: 'pending' } | { kind: 'failed' } | { kind: 'answered'; data: unknown }

const SERVER = {
  id: '01JSRVPLAN',
  name: 'node-01',
  environment: 'staging',
  state: 'discovered',
  safety: { classification: 'configuration_allowed', allow_reimage: false, reason: 'Lab machine.', changed_at: null, permits: { read: true, configure: true, reimage: false } },
  location: { datacenter_id: null, rack_id: null, rack_unit: null, height_units: null },
  hardware: { vendor: null, model: null, serial: null, asset_tag: null, operating_system: null },
  connection: { state: 'connected', blocker: null, management_address: 'fake://connected', bmc_address: null, credential: null, last_tested_at: null },
  last_discovery_at: null,
  last_deployment_at: null,
  last_verification_at: null,
  notes: null,
  created_at: '2026-09-01T00:00:00+00:00',
}

const ASSIGNED = {
  id: '01JDESIRED',
  server_id: SERVER.id,
  profile: 'web-node',
  profile_name: 'Web node',
  overrides: {},
  assigned_at: '2026-09-02T00:00:00+00:00',
}

const PAGE_META = { page: 1, per_page: 25, total: 1, last_page: 1, max_per_page: 100 }

function respond(status: number, body: unknown): Promise<Response> {
  return Promise.resolve({
    ok: status < 400,
    status,
    statusText: '',
    headers: new Headers(),
    text: () => Promise.resolve(body === null ? '' : JSON.stringify(body)),
  } as Response)
}

function stubFetch(desired: Read): string[] {
  const computed: string[] = []

  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const url = input instanceof Request ? input.url : String(input)
      const path = url.split('?')[0] ?? url
      const method = init?.method ?? 'GET'

      if (path.endsWith('/sanctum/csrf-cookie')) return respond(204, null)

      if (path.endsWith('/plan') && method === 'POST') {
        computed.push(path)
        return respond(200, { data: null })
      }

      if (path.endsWith('/desired-state')) {
        if (desired.kind === 'pending') return new Promise<Response>(() => undefined)
        if (desired.kind === 'failed') return respond(500, { error: { code: 'server.error', message: 'Server Error' } })
        return respond(200, { data: desired.data })
      }

      if (path.endsWith('/plan')) return respond(200, { data: null })
      if (path.endsWith('/infrastructure/profiles')) return respond(200, { data: [] })
      if (path.endsWith('/infrastructure/servers')) return respond(200, { data: [SERVER], meta: PAGE_META })

      throw new Error(`Unstubbed request: ${method} ${url}`)
    }),
  )

  return computed
}

async function openTheMachine(): Promise<void> {
  const user = userEvent.setup()

  render(
    <MemoryRouter>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <PlansPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )

  await screen.findByRole('option', { name: SERVER.name })
  await user.selectOptions(screen.getByLabelText(/^machine$/i), SERVER.id)
}

function computeButton(): HTMLElement {
  return screen.getByRole('button', { name: /compute plan/i })
}

describe('computing a plan waits for the desired state it is computed from', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('is not offered while the desired state is still being read', async () => {
    const computed = stubFetch({ kind: 'pending' })
    const user = userEvent.setup()

    await openTheMachine()
    await screen.findByText(/not planned yet/i)

    expect(computeButton()).toBeDisabled()

    await user.click(computeButton())
    expect(computed).toEqual([])
  })

  it('is not offered when the desired state could not be read', async () => {
    const computed = stubFetch({ kind: 'failed' })
    const user = userEvent.setup()

    await openTheMachine()
    await screen.findByRole('alert')

    expect(computeButton()).toBeDisabled()

    await user.click(computeButton())
    expect(computed).toEqual([])
  })

  it('is not offered when the machine has no desired state', async () => {
    const computed = stubFetch({ kind: 'answered', data: null })

    await openTheMachine()
    await screen.findByText(/no profile assigned/i)

    expect(computeButton()).toBeDisabled()
    expect(computed).toEqual([])
  })

  it('is offered once a desired state has been read, and sends the request', async () => {
    const computed = stubFetch({ kind: 'answered', data: ASSIGNED })
    const user = userEvent.setup()

    await openTheMachine()
    await screen.findByText('Web node')

    expect(computeButton()).toBeEnabled()

    await user.click(computeButton())
    await waitFor(() => {
      expect(computed).toHaveLength(1)
    })
    expect(computed[0]).toMatch(new RegExp(`/infrastructure/servers/${SERVER.id}/plan$`))
  })
})
