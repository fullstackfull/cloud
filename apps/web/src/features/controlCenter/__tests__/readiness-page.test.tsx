import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ReadinessPage } from '@/features/controlCenter/ReadinessPage'
import '@/i18n'

/*
 * The readiness screen without a browser: the ladder shows where a product
 * is, the blocker says what it is a blocker TO, and the one control — the
 * declaration — is not offered below production and cannot be sent without
 * a reason and a reference.
 */

const REQUIREMENT = {
  category: 'dns',
  capabilities: ['create_zone', 'records'],
  shared: false,
  satisfied_up_to: 'not_ready',
  provider_id: '01JPRV',
  provider_name: 'dns-live',
  blocker: 'blocked_credentials',
  next_action: 'controlCenter.guidance.credentials',
  detail: 'dns-live is not_ready (blocked).',
}

const BLOCKED = {
  product: 'dns',
  state: 'not_ready',
  next_state: 'ready_for_test',
  blocker: 'blocked_credentials',
  next_action: 'controlCenter.guidance.credentials',
  detail: 'dns: dns-live is not_ready (blocked).',
  depends_on: [],
  dependencies: {},
  requirements: [REQUIREMENT, { ...REQUIREMENT, category: 'payment', shared: true, provider_name: null, blocker: 'blocked_dependency', detail: 'No payment provider is registered.' }],
  sellable: { declared: false, declared_at: null, reason: null, validation_reference: null, withdrawn_at: null, withdrawn_reason: null },
  assessed_at: '2026-09-09T08:00:00+00:00',
  state_changed_at: null,
}

const READY = {
  ...BLOCKED,
  product: 'domains',
  state: 'ready_for_production',
  next_state: 'ready_to_sell',
  blocker: null,
  next_action: null,
  detail: 'Every requirement is met by a real provider enabled in production. Not yet declared sellable.',
  requirements: [{ ...REQUIREMENT, category: 'registrar', satisfied_up_to: 'ready_for_production', blocker: null, next_action: null, provider_name: 'sy-live' }],
}

function stubFetch(onDeclare?: (product: string, body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/sellable')) {
      onDeclare?.(path, parsed)
      body = { data: { ...READY, state: 'ready_to_sell', next_state: null, sellable: { ...READY.sellable, declared: true, declared_at: '2026-09-09T09:00:00+00:00', validation_reference: parsed?.validation_reference } } }
    } else if (path.endsWith('/readiness/products')) {
      body = { data: [BLOCKED, READY] }
    } else if (path.endsWith('/readiness/dependencies')) {
      body = { data: [{ product: 'dns', state: 'not_ready', depends_on: [], providers: [{ category: 'dns', shared: false, provider_id: '01JPRV', provider_name: 'dns-live', satisfied_up_to: 'not_ready' }] }] }
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
        <ReadinessPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('ReadinessPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the ladder with the reached rung marked, and the blocker as a blocker to the next one', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const products = await screen.findByRole('list', { name: /^product readiness$/i })
    const dns = within(products).getByRole('listitem', { name: /^dns$/i })
    const ladder = within(dns).getByRole('list', { name: /readiness ladder/i })
    expect(within(ladder).getByText(/^not ready$/i).closest('li')).toHaveAttribute('aria-current', 'step')
    expect(within(ladder).getAllByRole('listitem')).toHaveLength(5)

    expect(within(dns).getByText(/to reach ready for test/i)).toBeInTheDocument()
    expect(within(dns).getByText(/blocked: credentials/i)).toBeInTheDocument()
    expect(within(dns).getByText(/attach a credential the controller holds/i)).toBeInTheDocument()
    expect(screen.queryByText(/controlCenter\.guidance/)).not.toBeInTheDocument()
  })

  it('does not offer the declaration below production', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const products = await screen.findByRole('list', { name: /^product readiness$/i })
    const dns = within(products).getByRole('listitem', { name: /^dns$/i })
    expect(within(dns).getByRole('button', { name: /declare sellable/i })).toBeDisabled()
    expect(within(dns).getByText(/offered once the product is ready for production/i)).toBeInTheDocument()
  })

  it('declares only with a reason and a reference to the validation', async () => {
    const onDeclare = vi.fn()
    vi.stubGlobal('fetch', stubFetch(onDeclare))
    renderPage()

    const products = await screen.findByRole('list', { name: /^product readiness$/i })
    const domains = within(products).getByRole('listitem', { name: /^domains$/i })
    await userEvent.click(within(domains).getByRole('button', { name: /declare sellable/i }))

    const dialog = screen.getByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /declare sellable/i })
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/what was validated/i), 'Registered and renewed a name on the live account.')
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/where the validation is recorded/i), 'docs/validation/domains.md')
    await userEvent.click(confirm)

    await waitFor(() => {
      expect(onDeclare).toHaveBeenCalledWith(
        expect.stringContaining('/readiness/products/domains/sellable'),
        { reason: 'Registered and renewed a name on the live account.', validation_reference: 'docs/validation/domains.md' },
      )
    })
  })
})
