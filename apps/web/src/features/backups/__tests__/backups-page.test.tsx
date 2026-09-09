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
  is_being_deleted: false,
  deletion_requested_at: null,
  deleted_at: null,
  protected_until: null,
  started_at: '2026-03-01T00:00:00+00:00',
  finished_at: '2026-03-01T00:20:00+00:00',
  created_at: '2026-03-01T00:00:00+00:00',
  failure_reason: null,
  files: { supported: true, reason: null },
}

const LISTING = {
  path: '/etc',
  parent: '/',
  truncated: false,
  entries: [
    { path: '/etc/hostname', name: 'hostname', kind: 'file', size_bytes: 12, modified_at: null, downloadable: true, browsable: false, restorable: true },
    { path: '/etc/localtime', name: 'localtime', kind: 'symlink', size_bytes: null, modified_at: null, downloadable: false, browsable: false, restorable: false },
    { path: '/etc/nginx', name: 'nginx', kind: 'directory', size_bytes: null, modified_at: null, downloadable: false, browsable: true, restorable: true },
  ],
}

const PAGE_META = { page: 1, per_page: 25, total: 1, last_page: 1 }

interface Stubs {
  onRestore?: (body: unknown) => void
  onDelete?: (body: unknown) => void
  onKeep?: () => void
  onFileRestore?: (body: unknown) => void
  onDownload?: (body: unknown) => void
  /** What the list endpoint answers with, when the default row is not the case under test. */
  listed?: Record<string, unknown>
}

function stubFetch({ onRestore, onDelete, onKeep, onFileRestore, onDownload, listed }: Stubs = {}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const row = listed ?? BACKUP

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      // Every write goes through this first. Leaving it unstubbed made the
      // mutation fail before it ever reached the endpoint under test.
      body = null
    } else if (path.endsWith('/files/restore')) {
      onFileRestore?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { id: '01JFR', backup_id: BACKUP.id, state: 'running', is_in_flight: true, needs_attention: false, paths: ['/etc/hostname'], path_count: 1, started_at: null, finished_at: null, created_at: '2026-03-02T00:00:00+00:00', failure_reason: null } }
    } else if (path.endsWith('/files/downloads')) {
      onDownload?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { id: '01JDL', path: '/etc/hostname', expires_at: '2026-03-02T00:05:00+00:00', url: '/api/v1/backups/downloads/' + 'a'.repeat(64) } }
    } else if (path.endsWith('/files')) {
      body = { data: LISTING }
    } else if (path.endsWith('/file-restores')) {
      body = { data: [] }
    } else if (path.endsWith('/restore')) {
      // The client sends a JSON string; typing it as such keeps the lint
      // rule honest about what is being parsed.
      onRestore?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { ...BACKUP, state: 'restoring' } }
    } else if (path.endsWith('/keep')) {
      onKeep?.()
      body = { data: { ...row, is_being_deleted: false, deletion_requested_at: null } }
    } else if (init?.method === 'DELETE') {
      onDelete?.(JSON.parse(typeof init.body === 'string' ? init.body : '{}'))
      body = { data: { ...row, state: 'delete_requested', is_being_deleted: true } }
    } else if (path.endsWith('/backups')) {
      body = { data: [row], meta: PAGE_META }
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
    vi.stubGlobal('fetch', stubFetch({ onRestore: restored }))
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

  it('will not delete until the backup reference is typed back', async () => {
    const deleted = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onDelete: deleted }))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^delete$/i }))

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^delete$/i })
    const box = within(dialog).getByRole('textbox')

    expect(confirm).toBeDisabled()

    // The reference of a *different* backup is the mistake this guards
    // against — two tabs open, the wrong one confirmed.
    await user.type(box, '01JOTHER')
    expect(confirm).toBeDisabled()

    await user.clear(box)
    await user.type(box, '01JBACKUP')
    expect(confirm).toBeEnabled()

    await user.click(confirm)

    // What the customer typed, not the id the client already held: the
    // server's check is worthless if the client fills it in.
    await waitFor(() => {
      expect(deleted).toHaveBeenCalledWith({ confirm_backup_id: '01JBACKUP' })
    })
  })

  it('offers to keep a backup whose deletion has been asked for, and not to delete it again', async () => {
    const kept = vi.fn()
    vi.stubGlobal(
      'fetch',
      stubFetch({
        onKeep: kept,
        listed: {
          ...BACKUP,
          state: 'delete_requested',
          is_restorable: false,
          is_being_deleted: true,
          deletion_requested_at: '2026-03-02T00:00:00+00:00',
        },
      }),
    )
    const user = userEvent.setup()

    renderPage()

    expect(await screen.findByText(/deletion requested/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^delete$/i })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /^keep$/i }))

    // Keeping is the safe direction and asks for no confirmation: making a
    // customer type a reference to *not* lose their data is friction pointed
    // the wrong way.
    await waitFor(() => {
      expect(kept).toHaveBeenCalled()
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

  it('opens a backup file by file, never offers a symlink, and restores only after the hostname is typed', async () => {
    const restored = vi.fn()
    const downloaded = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ onFileRestore: restored, onDownload: downloaded }))
    const opened = vi.fn()
    vi.stubGlobal('open', opened)
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^files$/i }))

    // The listing, with the symlink marked and offered for nothing.
    expect(await screen.findByText('hostname')).toBeInTheDocument()
    expect(screen.getByText(/^link$/i)).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: /select localtime/i })).toBeDisabled()
    expect(screen.getAllByRole('button', { name: /^download$/i })).toHaveLength(1)

    // A download link is opened, never rendered.
    await user.click(screen.getByRole('button', { name: /^download$/i }))
    await waitFor(() => {
      expect(downloaded).toHaveBeenCalledWith({ path: '/etc/hostname' })
    })
    await waitFor(() => {
      expect(opened).toHaveBeenCalledWith('/api/v1/backups/downloads/' + 'a'.repeat(64), '_blank', 'noopener,noreferrer')
    })
    expect(screen.queryByText(/backups\/downloads/)).not.toBeInTheDocument()

    // Restore: choose, confirm with the hostname, and the typed value travels.
    await user.click(screen.getByRole('checkbox', { name: /select hostname/i }))
    await user.click(screen.getByRole('button', { name: /restore selected/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/replaced with the copy in the backup/i)).toBeInTheDocument()
    const confirm = within(dialog).getByRole('button', { name: /^restore files$/i })
    expect(confirm).toBeDisabled()
    await user.type(within(dialog).getByRole('textbox'), 'web-kw-01')
    await user.click(confirm)

    await waitFor(() => {
      expect(restored).toHaveBeenCalledWith({ paths: ['/etc/hostname'], confirmation: 'web-kw-01' })
    })
  })

  it('says why a backup cannot be opened file by file instead of hiding the button', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({ listed: { ...BACKUP, files: { supported: false, reason: 'This backup\'s provider cannot open archives file by file.' } } }),
    )

    renderPage()

    const files = await screen.findByRole('button', { name: /^files$/i })
    expect(files).toBeDisabled()
    expect(files).toHaveAttribute('title', expect.stringMatching(/cannot open archives/i))
  })
})
