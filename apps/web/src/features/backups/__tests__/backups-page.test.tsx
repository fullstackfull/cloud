import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { BackupsPage } from '@/features/backups/BackupsPage'

/**
 * The restore confirmation, tested at the component and not only in the
 * browser suite.
 *
 * A restore replaces every disk on a running machine. The server checks the
 * typed hostname and is what decides — but the client must not let a customer
 * click straight through, and "the button was disabled" is a claim worth an
 * assertion rather than a screenshot.
 */

const MACHINE = {
  id: '01JVM',
  service_id: '01JSERVICE',
  hostname: 'web-kw-01',
  service_status: 'active',
  power_state: 'running',
  vcpu: 2,
  memory_mib: 4096,
  disk_gib: 40,
  os_family: 'debian',
  os_version: '12',
  addresses: [],
  is_operable: true,
}

const BACKUP = {
  id: '01JBACKUP',
  service_id: '01JSERVICE',
  state: 'succeeded',
  trigger: 'manual',
  mode: 'snapshot',
  is_in_flight: false,
  is_restorable: true,
  needs_attention: false,
  size_bytes: 2 * 1024 ** 3,
  verified: false,
  verified_at: null,
  retention_days: 7,
  expires_at: null,
  started_at: '2026-03-01T00:00:00+00:00',
  finished_at: '2026-03-01T00:20:00+00:00',
  created_at: '2026-03-01T00:00:00+00:00',
  failure_reason: null,
}

const PAGE_META = { page: 1, per_page: 25, total: 1, last_page: 1 }

function stubFetch(onRestore?: (body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      // Every write goes through this first. Leaving it unstubbed made the
      // mutation fail before it ever reached the endpoint under test.
      body = null
    } else if (path.endsWith('/restore')) {
      // The client sends a JSON string; typing it as such keeps the lint
      // rule honest about what is being parsed.
      onRestore?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { ...BACKUP, state: 'restoring' } }
    } else if (path.endsWith('/backups')) {
      body = { data: [BACKUP], meta: PAGE_META }
    } else if (path.endsWith('/vps')) {
      body = { data: [MACHINE], meta: PAGE_META }
    } else {
      // Loudly, so a page that starts calling something new cannot pass by
      // returning an empty body.
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
      <BackupsPage />
    </QueryClientProvider>,
  )
}

describe('backups page', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lists a machine's backups", async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByText('2.0 GiB')).toBeInTheDocument()

    // Completed and restore-tested are different facts, shown as such: a
    // backup nobody has restored from is not known to work.
    expect(screen.getByText(/not tested/i)).toBeInTheDocument()
  })

  it('will not restore until the hostname is typed exactly', async () => {
    const restored = vi.fn()
    vi.stubGlobal('fetch', stubFetch(restored))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^restore$/i }))

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^restore$/i })
    const box = within(dialog).getByRole('textbox')

    expect(confirm).toBeDisabled()

    // A near miss is still a miss. DNS is case-insensitive; this is not a
    // lookup, it is evidence the customer read the screen.
    await user.type(box, 'WEB-KW-01')
    expect(confirm).toBeDisabled()

    await user.clear(box)
    await user.type(box, 'web-kw-01')
    expect(confirm).toBeEnabled()

    await user.click(confirm)

    await waitFor(() => {
      expect(restored).toHaveBeenCalledWith({ confirmation: 'web-kw-01' })
    })
  })

  it('says plainly what a restore destroys', async () => {
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^restore$/i }))

    const dialog = await screen.findByRole('dialog')

    expect(
      within(dialog).getByText(/anything written since the backup was taken is lost/i),
    ).toBeInTheDocument()
    expect(within(dialog).getByText(/cannot be undone/i)).toBeInTheDocument()
  })
})
