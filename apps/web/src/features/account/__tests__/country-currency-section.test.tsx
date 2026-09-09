import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { CountryCurrencySection } from '@/features/account/CountryCurrencySection'
import type { CustomerSummary } from '@/features/auth/useAuth'
import '@/i18n'

/**
 * The request to change what an account is billed in, at the component.
 *
 * Two things worth an assertion: that the screen says nothing already
 * issued is converted before it lets anybody ask, and that a blocked
 * request is shown with the blockers in words and no way to apply it —
 * only to check again once they are cleared, or to withdraw.
 */

const CUSTOMER: CustomerSummary = {
  id: '01JCUST',
  type: 'organization',
  status: 'active',
  display_name: 'Sample Customer',
  legal_name: null,
  currency: 'KWD',
  country: 'KW',
  can_purchase: true,
  role: 'owner',
}

const BLOCKED = {
  id: '01JCHANGE',
  customer_id: '01JCUST',
  state: 'blocked',
  is_open: true,
  needs_attention: false,
  from_country: 'KW',
  to_country: 'SA',
  from_currency: 'KWD',
  to_currency: 'USD',
  reason: 'Moving to Riyadh.',
  impact: {
    facts: { open_invoices: 1, active_subscriptions: 1 },
    blockers: ['1 open invoice(s) in KWD must be paid or voided first. An invoice is never converted.'],
    warnings: ['Every invoice, payment and order already recorded stays in KWD.'],
  },
  decision_note: null,
  analysed_at: '2026-03-01T00:00:00+00:00',
  decided_at: null,
  scheduled_for: null,
  applied_at: null,
  created_at: '2026-03-01T00:00:00+00:00',
}

function stubFetch({ listed, onRequest }: { listed?: unknown[]; onRequest?: (body: unknown) => void } = {}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/country-currency-changes') && init?.method === 'POST') {
      onRequest?.(JSON.parse(typeof init.body === 'string' ? init.body : '{}'))
      body = { data: BLOCKED }
    } else if (path.endsWith('/country-currency-changes')) {
      body = { data: listed ?? [], meta: { currencies: ['KWD', 'USD'] } }
    } else if (path.endsWith('/reanalyse')) {
      body = { data: { ...BLOCKED, state: 'awaiting_approval', impact: { ...BLOCKED.impact, blockers: [] } } }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({ ok: true, status: 200, statusText: '', headers: new Headers(), text: () => Promise.resolve(JSON.stringify(body)) } as Response)
  })
}

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <CountryCurrencySection customer={CUSTOMER} />
    </QueryClientProvider>,
  )
}

describe('country and currency section', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('says nothing already issued is converted, and sends the ask as a request rather than a setting', async () => {
    const asked = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onRequest: asked }))
    const user = userEvent.setup()

    renderSection()

    expect(await screen.findByTestId('billed-in')).toHaveTextContent('Billed in KWD · KW')
    expect(screen.getByText(/nothing already issued is ever converted/i)).toBeInTheDocument()

    await user.click(await screen.findByRole('button', { name: /request a change/i }))
    await user.clear(screen.getByLabelText(/country/i))
    await user.type(screen.getByLabelText(/country/i), 'sa')
    await user.selectOptions(screen.getByLabelText(/^currency$/i), 'USD')
    await user.type(screen.getByLabelText(/^why$/i), 'Moving to Riyadh.')
    await user.click(screen.getByRole('button', { name: /send the request/i }))

    await waitFor(() => {
      expect(asked).toHaveBeenCalledWith({ country: 'SA', currency: 'USD', reason: 'Moving to Riyadh.' })
    })
  })

  it('shows a blocked request with its blockers in words, and offers only to check again or withdraw', async () => {
    vi.stubGlobal('fetch', stubFetch({ listed: [BLOCKED] }))

    renderSection()

    const change = await screen.findByTestId('country-currency-change')
    expect(within(change).getByText(/^blocked$/i)).toBeInTheDocument()
    expect(within(change).getByRole('list', { name: /what must change first/i })).toHaveTextContent(/never converted/i)
    expect(within(change).getByRole('button', { name: /check again/i })).toBeInTheDocument()
    expect(within(change).getByRole('button', { name: /withdraw/i })).toBeInTheDocument()

    // No way to ask again while one is open, and nothing that applies it.
    expect(screen.queryByRole('button', { name: /request a change/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /apply|approve/i })).not.toBeInTheDocument()
  })
})
