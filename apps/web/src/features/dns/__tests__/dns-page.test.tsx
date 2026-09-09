import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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
 * The DNS screen, tested where the browser suite cannot reach cheaply.
 *
 * Two things are worth an assertion rather than a screenshot: that the screen
 * says what has to happen at the registrar (a zone here serves nothing until
 * the domain is delegated, and no badge on this page means otherwise), and
 * that the record form sends a fully-qualified name rather than whatever was
 * typed in the box.
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
  record_count: 1,
  last_synced_at: null,
  created_at: '2026-03-01T00:00:00+00:00',
}

const RECORD = {
  id: '01JRECORD',
  zone_id: '01JZONE',
  type: 'A',
  name: 'www.example.test',
  content: '203.0.113.10',
  ttl: 1,
  priority: null,
  caa_flags: null,
  caa_tag: null,
  caa_value: null,
  state: 'active',
  is_live: true,
  is_being_deleted: false,
  needs_attention: false,
  failure_reason: null,
  last_published_at: '2026-03-01T00:00:00+00:00',
  created_at: '2026-03-01T00:00:00+00:00',
}

const PLAN = {
  zone_id: '01JZONE',
  zone: 'example.test',
  mode: 'merge',
  applicable: true,
  fingerprint: 'a'.repeat(64),
  counts: { add: 1, update: 0, remove: 0, unchanged: 1, refused: 0, ignored: 1, kept: 0 },
  entries: [
    { kind: 'add', line: 2, type: 'A', name: 'api.example.test', content: '203.0.113.20', ttl: 3600, priority: null, existing_id: null, reason: null },
    { kind: 'unchanged', line: 1, type: 'A', name: 'www.example.test', content: '203.0.113.10', ttl: 1, priority: null, existing_id: '01JRECORD', reason: null },
    { kind: 'ignored', line: 3, type: null, name: null, content: '@ IN NS a.ns.fake.test.', ttl: null, priority: null, existing_id: null, reason: 'the nameservers set the apex NS' },
  ],
}

const REFUSED_PLAN = {
  ...PLAN,
  applicable: false,
  counts: { ...PLAN.counts, refused: 1 },
  entries: [
    ...PLAN.entries,
    { kind: 'refused', line: 4, type: null, name: null, content: '$INCLUDE /etc/passwd', ttl: null, priority: null, existing_id: null, reason: '$INCLUDE names a file on somebody\'s disk; a zone file may not do that here.' },
  ],
}

interface Stubs {
  onAdd?: (body: unknown) => void
  onRelease?: (body: unknown) => void
  onApply?: (body: unknown) => void
  zone?: Record<string, unknown>
  plan?: Record<string, unknown>
}

function stubFetch({ onAdd, onRelease, onApply, zone, plan }: Stubs = {}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const row = zone ?? ZONE

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/import/plan')) {
      body = { data: plan ?? PLAN }
    } else if (path.endsWith('/import')) {
      onApply?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { zone_id: '01JZONE', mode: 'merge', added: 1, updated: 0, removed: 0, unchanged: 1 } }
    } else if (path.endsWith('/export')) {
      body = { data: { filename: 'example.test.zone', content: '$ORIGIN example.test.\nwww 300 IN A 203.0.113.10\n', record_count: 1 } }
    } else if (path.endsWith('/records') && init?.method === 'POST') {
      onAdd?.(JSON.parse(typeof init.body === 'string' ? init.body : '{}'))
      body = { data: RECORD }
    } else if (path.endsWith('/records')) {
      body = { data: [RECORD], meta: { total: 1 } }
    } else if (init?.method === 'DELETE') {
      onRelease?.(JSON.parse(typeof init.body === 'string' ? init.body : '{}'))
      body = { data: { ...row, state: 'deleted' } }
    } else if (path.endsWith('/dns/zones')) {
      body = { data: [row], meta: { total: 1 } }
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
    <QueryClientProvider client={client}>
      <DnsPage />
    </QueryClientProvider>,
  )
}

describe('dns page', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('says what has to happen at the registrar before any of this takes effect', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('a.ns.fake.test')).toBeInTheDocument()
    expect(screen.getByText(/does not check who owns a domain/i)).toBeInTheDocument()
  })

  it('sends a fully-qualified name rather than what was typed in the box', async () => {
    const added = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onAdd: added }))
    const user = userEvent.setup()

    renderPage()

    await user.type(await screen.findByLabelText(/name \(blank for/i), 'www')
    await user.type(screen.getByLabelText(/^value$/i), '203.0.113.10')
    await user.click(screen.getByRole('button', { name: /add record/i }))

    // Not 'www'. A form that sends the fragment and lets the server guess is a
    // form that publishes www.example.test.example.test the first time
    // somebody types the whole name.
    await waitFor(() => {
      expect(added).toHaveBeenCalledWith(
        expect.objectContaining({ name: 'www.example.test', content: '203.0.113.10', type: 'A' }),
      )
    })
  })

  it('will not give up a domain until its name is typed back', async () => {
    const released = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onRelease: released }))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /give up domain/i }))

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /give up domain/i })

    expect(confirm).toBeDisabled()
    expect(within(dialog).getByText(/stops resolving/i)).toBeInTheDocument()

    await user.type(within(dialog).getByRole('textbox'), 'example.test')
    expect(confirm).toBeEnabled()

    await user.click(confirm)

    await waitFor(() => {
      expect(released).toHaveBeenCalledWith({ confirm_zone_name: 'example.test' })
    })
  })

  it('does not tell a customer to try again when the platform does not know what happened', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        zone: { ...ZONE, state: 'indeterminate', is_live: false, needs_attention: true },
      }),
    )

    renderPage()

    // The zone may be perfectly fine. Repeating the request is the one thing
    // that could make it worse, so the message says so.
    expect(await screen.findByText(/cannot say whether it took effect/i)).toBeInTheDocument()
    expect(screen.getByText(/could duplicate it/i)).toBeInTheDocument()
  })

  it('applies exactly the plan it previewed, and only after the zone name is typed back', async () => {
    const applied = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onApply: applied }))
    const user = userEvent.setup()

    renderPage()

    const text = "www IN A 203.0.113.10\napi IN A 203.0.113.20\n@ IN NS a.ns.fake.test.\n"
    await user.type(await screen.findByLabelText(/or paste the zone text/i), text)
    await user.click(screen.getByRole('button', { name: /preview changes/i }))

    const planned = await screen.findByTestId('zone-import-plan')
    expect(within(planned).getByText('api.example.test A 203.0.113.20')).toBeInTheDocument()
    // The ignored line is listed with its reason, not dropped on the floor.
    expect(within(planned).getByText(/nameservers set the apex NS/i)).toBeInTheDocument()

    await user.click(within(planned).getByRole('button', { name: /apply this plan/i }))

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /apply this plan/i })
    expect(confirm).toBeDisabled()
    await user.type(within(dialog).getByRole('textbox'), 'example.test')
    await user.click(confirm)

    // The preview's fingerprint travels with the apply: the server applies
    // what was shown or refuses, never a plan nobody saw.
    await waitFor(() => {
      expect(applied).toHaveBeenCalledWith(
        expect.objectContaining({ mode: 'merge', fingerprint: 'a'.repeat(64) }),
      )
    })
    expect(await screen.findByText(/imported: 1 added/i)).toBeInTheDocument()
  })

  it('applies nothing while any line is refused, and says which line and why', async () => {
    const applied = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onApply: applied, plan: REFUSED_PLAN }))
    const user = userEvent.setup()

    renderPage()

    await user.type(await screen.findByLabelText(/or paste the zone text/i), '$INCLUDE /etc/passwd')
    await user.click(screen.getByRole('button', { name: /preview changes/i }))

    const planned = await screen.findByTestId('zone-import-plan')
    expect(within(planned).getByText(/nothing will be applied while any line is refused/i)).toBeInTheDocument()
    expect(within(planned).getByText(/names a file on somebody/i)).toBeInTheDocument()
    expect(within(planned).getByRole('button', { name: /apply this plan/i })).toBeDisabled()
    expect(applied).not.toHaveBeenCalled()
  })

  it('exports the zone as text the customer can read and take away', async () => {
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /export zone file/i }))

    expect(await screen.findByTestId('zone-export')).toHaveTextContent('www 300 IN A 203.0.113.10')
    expect(screen.getByRole('link', { name: /download example\.test\.zone/i })).toHaveAttribute('download', 'example.test.zone')
  })
})
