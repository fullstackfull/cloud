import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { LicencesPage } from '@/features/controlCenter/LicencesPage'
import '@/i18n'

/*
 * The licence screen's contract: states are words, expiring is flagged as
 * needing attention while still permitted, a renewal cannot be sent without
 * a date, and an invalidation cannot be sent without a reason.
 */

const ACTIVE = {
  id: '01JLICACTIVE',
  product: 'cpanel',
  licence_type: 'admin',
  environment: 'staging',
  state: 'active',
  permits: true,
  needs_attention: false,
  days_remaining: 300,
  starts_on: '2026-01-01',
  expires_on: '2027-07-01',
  renews_on: null,
  seats: 100,
  external_reference: 'ORDER-1',
  server: null,
  credential: null,
  usage: { providers: 1 },
  invalidated_at: null,
  invalidated_reason: null,
  renewed_at: null,
  state_changed_at: null,
  notes: null,
  created_at: '2026-01-01T00:00:00+00:00',
}

const EXPIRING = { ...ACTIVE, id: '01JLICEXPIRING', product: 'directadmin', state: 'expiring', needs_attention: true, days_remaining: 12 }

const PAGE_META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch(spy: (path: string, body: unknown) => void = () => undefined) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const parsed: unknown = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/invalidate')) {
      spy(path, parsed)
      body = { data: { ...ACTIVE, state: 'invalid', permits: false, invalidated_reason: 'Refunded.' } }
    } else if (path.endsWith('/renew')) {
      spy(path, parsed)
      body = { data: { ...ACTIVE } }
    } else if (path.endsWith('/licences')) {
      body = { data: [EXPIRING, ACTIVE], meta: PAGE_META }
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
        <LicencesPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('LicencesPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows expiring as needing attention while active is left alone', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const expiring = await screen.findByRole('row', { name: /directadmin/i })
    expect(within(expiring).getByText(/^expiring$/i)).toBeInTheDocument()
    expect(within(expiring).getByText(/needs attention/i)).toBeInTheDocument()
    expect(within(expiring).getByText(/12 days left/i)).toBeInTheDocument()

    const active = screen.getByRole('row', { name: /cpanel/i })
    expect(within(active).getByText(/^active$/i)).toBeInTheDocument()
    expect(within(active).queryByText(/needs attention/i)).not.toBeInTheDocument()
  })

  it('will not invalidate without a reason', async () => {
    const spy = vi.fn()
    vi.stubGlobal('fetch', stubFetch(spy))
    renderPage()

    const active = await screen.findByRole('row', { name: /cpanel/i })
    await userEvent.click(within(active).getByRole('button', { name: /mark invalid/i }))

    const dialog = screen.getByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /mark invalid/i })
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/why\?/i), 'Refunded.')
    await userEvent.click(confirm)

    await waitFor(() => { expect(spy).toHaveBeenCalledWith(expect.stringContaining('/invalidate'), { reason: 'Refunded.' }); })
  })

  it('will not renew without a date', async () => {
    const spy = vi.fn()
    vi.stubGlobal('fetch', stubFetch(spy))
    renderPage()

    const active = await screen.findByRole('row', { name: /cpanel/i })
    await userEvent.click(within(active).getByRole('button', { name: /^renew$/i }))

    const dialog = screen.getByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^renew$/i })
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/new expiry/i), '2027-12-31')
    expect(confirm).toBeEnabled()
    await userEvent.click(confirm)

    await waitFor(() => { expect(spy).toHaveBeenCalledWith(expect.stringContaining('/renew'), { expires_on: '2027-12-31' }); })
  })
})
