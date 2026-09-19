import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  VpsDangerSection,
  VpsDetailPage,
  VpsNetworkingSection,
  VpsOverviewSection,
} from '@/features/infrastructure/VpsDetailPage'
import '@/i18n'

/**
 * One machine's own page: the audit's AR-7.
 *
 * This file carries two kinds of assertion.
 *
 * The Wave 3 ones: that the page names the machine rather than its id, that
 * its sections are addresses a customer can link to, and that the rebuild
 * offers the images the platform actually staged for this machine along with
 * public SSH keys.
 *
 * And Wave 0's, which must not regress now that the controls have moved here
 * from the list: force off asks first, a confirmed force off is exactly one
 * request with an idempotency key in the header, and a machine the API says it
 * would refuse has its controls off with the reason said in words.
 */

const MACHINE = {
  id: '01JVM',
  service_id: '01JSERVICE',
  hostname: 'web-kw-01',
  service_status: 'active',
  power_state: 'running',
  resources: { vcpu: 2, memory_mib: 4096, disk_gib: 40 },
  os_family: 'debian',
  os_version: '12',
  addresses: [
    { address: '198.51.100.24', ip_version: 4, is_primary: true },
    { address: '2001:db8::24', ip_version: 6, is_primary: false },
  ],
  is_operable: true,
  actions: { power: true, reinstall: true, blocked_reason: null },
  reinstall: null,
  created_at: '2026-03-01T00:00:00+00:00',
}

const STRANDED = {
  ...MACHINE,
  actions: { power: false, reinstall: false, blocked_reason: 'operation_needs_review' },
  reinstall: {
    id: '01JREINSTALL',
    state: 'indeterminate',
    in_flight: false,
    needs_attention: true,
    data_destroyed: true,
    requested_at: '2026-03-01T00:00:00+00:00',
    completed_at: null,
  },
}

/**
 * Two images, one of which cannot take a key on first boot. Nothing in the
 * portal names a distribution: this is the platform's own list for this
 * machine, which is the only list the endpoint would accept an id from.
 */
const TEMPLATES = [
  {
    id: '01JTPL',
    name: 'Debian 12',
    os_family: 'debian',
    os_version: '12',
    architecture: 'x86_64',
    supports_ssh_keys: true,
    requires_licence: false,
  },
  {
    id: '01JTPLWIN',
    name: 'Windows Server 2022',
    os_family: 'windows',
    os_version: '2022',
    architecture: 'x86_64',
    supports_ssh_keys: false,
    requires_licence: true,
  },
]

function stubFetch({
  machine = MACHINE,
  onPower,
  onReinstall,
}: {
  machine?: unknown
  onPower?: (headers: Headers, body: unknown) => void
  onReinstall?: (headers: Headers, body: unknown) => void
} = {}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/power')) {
      onPower?.(new Headers(init?.headers), JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { kind: 'stop', status: 'queued' } }
    } else if (path.endsWith('/reinstall')) {
      onReinstall?.(new Headers(init?.headers), JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { id: '01JNEW', state: 'requested' } }
    } else if (path.endsWith('/templates')) {
      body = { data: TEMPLATES }
    } else if (path.endsWith('/vps/01JVM')) {
      body = { data: machine }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    const response = {
      ok: true,
      status: 200,
      statusText: '',
      headers: new Headers(),
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response

    /*
     * A power request answers on the next macrotask rather than instantly, so
     * that the second of two clicks lands while the first is still in flight
     * — which is the situation the guarantee is about. A stub that resolves
     * synchronously would let the dialogue close between the two clicks and
     * quietly stop testing anything.
     */
    return path.endsWith('/power')
      ? new Promise((resolve) => { setTimeout(() => { resolve(response) }, 20) })
      : Promise.resolve(response)
  })
}

/**
 * The machine's page with its sections mounted exactly as the application
 * mounts them: child routes of the shell, not panels inside a component.
 */
function renderPage(path = '/vps/01JVM') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter initialEntries={[path]}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/vps/:id" element={<VpsDetailPage />}>
            <Route index element={<VpsOverviewSection />} />
            <Route path="networking" element={<VpsNetworkingSection />} />
            <Route path="danger" element={<VpsDangerSection />} />
          </Route>
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('a machine of its own', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('is titled with the hostname, and its trail carries names rather than ids', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    expect(await screen.findByRole('heading', { level: 1, name: 'web-kw-01' })).toBeInTheDocument()

    const trail = screen.getByRole('navigation', { name: /you are here/i })
    expect(within(trail).getByText('web-kw-01')).toBeInTheDocument()
    // A breadcrumb of ULIDs tells the reader nothing and cannot be read out
    // to support over the phone.
    expect(trail.textContent).not.toContain('01JVM')

    // The facts a customer came for, on the section that costs one request.
    expect(screen.getByText('198.51.100.24')).toBeInTheDocument()
    expect(screen.getByText(/debian 12/i)).toBeInTheDocument()
  })

  it('makes each section an address, not a panel', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage('/vps/01JVM/networking')

    // Reached directly, by URL, as a colleague would open a link.
    expect(await screen.findByText('2001:db8::24')).toBeInTheDocument()

    const sections = screen.getByRole('navigation', { name: /sections/i })
    const backups = within(sections).getByRole('link', { name: /backups/i })
    expect(backups).toHaveAttribute('href', '/vps/01JVM/backups')

    // The section the reader is on is announced as the current page rather
    // than merely underlined.
    expect(within(sections).getByRole('link', { name: /networking/i })).toHaveAttribute(
      'aria-current',
      'page',
    )
  })

  it('asks before forcing a machine off, and does nothing when the customer backs out', async () => {
    const powered = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onPower: powered }))
    const user = userEvent.setup()

    renderPage()
    await screen.findByRole('heading', { level: 1, name: 'web-kw-01' })

    await user.click(screen.getByRole('button', { name: /^force off$/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/pulls the plug/i)).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: /^cancel$/i }))

    expect(powered).not.toHaveBeenCalled()
  })

  it('forces off exactly once when confirmed, with the key in the header', async () => {
    const powered = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onPower: powered }))
    const user = userEvent.setup()

    renderPage()
    await screen.findByRole('heading', { level: 1, name: 'web-kw-01' })

    await user.click(screen.getByRole('button', { name: /^force off$/i }))
    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^force off$/i })

    // Two presses: the second lands on a button that is loading, and so
    // disabled, which is what stops a slow network turning one decision into
    // two requests.
    await user.click(confirm)
    await user.click(confirm)

    await waitFor(() => {
      expect(powered).toHaveBeenCalledTimes(1)
    })

    const [headers, body] = powered.mock.calls[0] as [Headers, Record<string, unknown>]
    expect(body).toEqual({ action: 'stop' })
    expect(headers.get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('turns every control off on a machine the API says it would refuse, and says why', async () => {
    vi.stubGlobal('fetch', stubFetch({ machine: STRANDED }))

    renderPage()
    await screen.findByRole('heading', { level: 1, name: 'web-kw-01' })

    for (const name of [/^start$/i, /^shut down$/i, /^force off$/i, /^reboot$/i]) {
      expect(screen.getByRole('button', { name })).toBeDisabled()
    }

    expect(screen.getByText(/our team is looking at it\. controls stay off/i)).toBeInTheDocument()
  })

  it('rebuilds only from the images the platform staged, and takes public keys where they work', async () => {
    const rebuilt = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onReinstall: rebuilt }))
    const user = userEvent.setup()

    renderPage('/vps/01JVM/danger')

    await user.click(await screen.findByRole('button', { name: /^reinstall$/i }))

    const dialog = await screen.findByRole('dialog')
    const images = within(dialog).getByRole('combobox', { name: /operating system/i })

    // The platform's own list for this machine, plus the honest default of
    // keeping what is already installed. Nothing is hard-coded here.
    expect(
      within(images)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual([
      'Keep the current image',
      'Debian 12 · 12 · x86_64',
      'Windows Server 2022 · 2022 · x86_64',
    ])

    // No key field until an image that can actually take one is chosen.
    expect(within(dialog).queryByLabelText(/public ssh keys/i)).not.toBeInTheDocument()

    await user.selectOptions(images, '01JTPLWIN')
    expect(within(dialog).queryByLabelText(/public ssh keys/i)).not.toBeInTheDocument()
    expect(within(dialog).getByText(/cannot be given a key on first boot/i)).toBeInTheDocument()

    await user.selectOptions(images, '01JTPL')
    const keys = within(dialog).getByLabelText(/public ssh keys/i)
    await user.type(keys, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 someone@example.com')

    const confirm = within(dialog).getByRole('button', { name: /^reinstall this server$/i })
    expect(confirm).toBeDisabled()

    await user.type(
      within(dialog).getByRole('textbox', { name: /type the hostname to confirm/i }),
      'web-kw-01',
    )
    await user.click(confirm)

    await waitFor(() => {
      expect(rebuilt).toHaveBeenCalledTimes(1)
    })

    const [headers, body] = rebuilt.mock.calls[0] as [Headers, Record<string, unknown>]
    expect(body).toEqual({
      confirm_hostname: 'web-kw-01',
      template_id: '01JTPL',
      ssh_keys: ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 someone@example.com'],
    })
    expect(headers.get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/)
  })
})
