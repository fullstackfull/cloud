import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { DnsPage } from '@/features/dns/DnsPage'
/*
 * Imported for its side effect: this is what initialises i18next. Pages that
 * happen to import a locale helper get it for free, and a test asserting on
 * English text without it is really asserting that a translation key is
 * missing.
 */
import '@/i18n'

/**
 * The DNS index, which since Wave 3 is all this screen is.
 *
 * It used to be the whole of DNS: a select box in front of one zone's
 * delegation, records and import, which meant "the records of example.test"
 * was not an address anybody could send or bookmark. Those are now the zone's
 * own page — see dns-zone.test.tsx — and what is left here is the list and the
 * one thing that cannot live on a zone's page because there is no zone yet:
 * claiming one.
 */

const ZONE = {
  id: '01JZONE',
  name: 'example.test',
  service_id: null,
  state: 'active',
  is_live: true,
  is_being_deleted: false,
  needs_attention: false,
  nameservers: ['a.ns.fake.test', 'b.ns.fake.test'],
  failure_reason: null,
  record_count: 3,
  last_synced_at: null,
  created_at: '2026-03-01T00:00:00+00:00',
}

function stubFetch({ onClaim, zones = [ZONE] }: { onClaim?: (body: unknown) => void; zones?: unknown[] } = {}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/dns/zones') && init?.method === 'POST') {
      onClaim?.(JSON.parse(typeof init.body === 'string' ? init.body : '{}'))
      body = { data: ZONE }
    } else if (path.endsWith('/dns/zones')) {
      body = { data: zones, meta: { total: zones.length } }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: true,
      status: 200,
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
        <DnsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('the DNS index', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists each zone as a link to it, addressed by the name a customer recognises', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const zone = await screen.findByRole('link', { name: 'example.test' })

    // The name, not the ULID: it is what the customer reads out to support
    // and what the API accepts alongside the id.
    expect(zone).toHaveAttribute('href', '/dns/example.test')
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('claims a domain from here, because at that moment there is no zone to open', async () => {
    const claimed = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onClaim: claimed }))
    const user = userEvent.setup()

    renderPage()

    await user.type(await screen.findByLabelText(/^domain$/i), 'second.test')
    await user.click(screen.getByRole('button', { name: /add domain/i }))

    await waitFor(() => {
      expect(claimed).toHaveBeenCalledWith({ name: 'second.test' })
    })
  })

  it('says what to do when there is nothing here yet, rather than showing an empty table', async () => {
    vi.stubGlobal('fetch', stubFetch({ zones: [] }))

    renderPage()

    expect(await screen.findByText(/no domains here yet/i)).toBeInTheDocument()
  })
})
