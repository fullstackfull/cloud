import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { BackupsPage } from '@/features/backups/BackupsPage'

/**
 * What a customer is told about one backup, in each state it can be in.
 *
 * The defect this pins: `verified` is three-valued, and the row asked
 * `=== true` and sent everything else to "Not tested". An archive the
 * datastore had read back and failed therefore looked exactly like one nobody
 * had got round to checking — the worst of the three answers wearing the
 * neutral one's words — with a Restore button beside it.
 *
 * Null is still "Not tested", deliberately. The product contract on
 * BackupState::isRestorable says a customer facing a lost machine would rather
 * try an unverified backup than be told no, so nothing here may quietly turn
 * "nobody checked" into "broken".
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

const PAGE_META = { page: 1, per_page: 25, total: 1, last_page: 1 }

/** The row as the API renders it, with only what the case under test changes. */
function backup(overrides: Record<string, unknown>) {
  return {
    id: '01JBACKUP',
    service_id: '01JSERVICE',
    state: 'succeeded',
    trigger: 'manual',
    mode: 'snapshot',
    is_in_flight: false,
    is_restorable: true,
    needs_attention: false,
    size_bytes: 2 * 1024 ** 3,
    verified: null,
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
    ...overrides,
  }
}

function renderWith(row: Record<string, unknown>) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL): Promise<Response> => {
      const url = input instanceof Request ? input.url : String(input)
      const path = url.split('?')[0] ?? url

      const body = path.endsWith('/vps')
        ? { data: [MACHINE], meta: PAGE_META }
        : path.endsWith('/file-restores')
          ? { data: [] }
          : path.endsWith('/backups')
            ? { data: [row], meta: PAGE_META }
            : null

      if (body === null) {
        throw new Error(`Unstubbed request: ${url}`)
      }

      return Promise.resolve({
        ok: true,
        status: 200,
        statusText: '',
        text: () => Promise.resolve(JSON.stringify(body)),
      } as Response)
    }),
  )

  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <BackupsPage />
    </QueryClientProvider>,
  )
}

function restoreButton(): HTMLButtonElement {
  return screen.getAllByRole('button', { name: 'Restore' })[0] as HTMLButtonElement
}

describe('what a backup row says', () => {
  it('says a verified backup was restore tested, and offers the restore', async () => {
    renderWith(backup({ verified: true, verified_at: '2026-03-01T01:00:00+00:00' }))

    expect(await screen.findByText('Restore tested')).toBeInTheDocument()
    await waitFor(() => { expect(restoreButton()).not.toBeDisabled() })
  })

  it('says an unreadable backup is unreadable, and will not offer the restore', async () => {
    // The row the API can now produce: the datastore read it back and it did
    // not come back. `is_restorable` is false because the server says so.
    renderWith(backup({ verified: false, is_restorable: false }))

    expect(await screen.findByText('Unreadable')).toBeInTheDocument()
    expect(screen.queryByText('Not tested')).not.toBeInTheDocument()
    await waitFor(() => { expect(restoreButton()).toBeDisabled() })

    // And it says why, rather than leaving a greyed-out button to be guessed at.
    expect(restoreButton().title).toContain('did not come back')
  })

  it('says an unchecked backup is untested, and still offers the restore', async () => {
    renderWith(backup({ verified: null }))

    expect(await screen.findByText('Not tested')).toBeInTheDocument()
    expect(screen.queryByText('Unreadable')).not.toBeInTheDocument()
    await waitFor(() => { expect(restoreButton()).not.toBeDisabled() })
  })

  it('shows a running restore as in flight rather than as finished', async () => {
    renderWith(backup({ state: 'restoring', is_in_flight: true, is_restorable: false }))

    expect(await screen.findByText('Restoring')).toBeInTheDocument()
    await waitFor(() => { expect(restoreButton()).toBeDisabled() })
  })

  it('shows a finished restore as restored', async () => {
    renderWith(backup({ state: 'restored', verified: null }))

    expect(await screen.findByText('Restored')).toBeInTheDocument()
  })

  it('shows a failed restore as the backup it still is, with the reason', async () => {
    /*
     * A failed restore leaves the archive intact, so the row goes back to
     * succeeded — which is true, and would be misleading on its own. The
     * reason is what tells the customer the restore did not happen.
     */
    renderWith(backup({ state: 'succeeded', failure_reason: 'target volume is read-only' }))

    expect(await screen.findByText(/read-only/)).toBeInTheDocument()
  })
})
