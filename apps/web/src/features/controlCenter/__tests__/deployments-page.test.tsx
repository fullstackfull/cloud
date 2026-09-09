import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { DeploymentsPage } from '@/features/controlCenter/DeploymentsPage'
import '@/i18n'

/*
 * The deployments screen without a browser: an indeterminate run is marked
 * as waiting for a person, the only control on it asks what they found, and
 * a completed run offers nothing.
 */

const INDETERMINATE = {
  id: '01JJOBIND',
  server_id: '01JSRV',
  server_name: 'redis-01',
  kind: 'apply',
  state: 'indeterminate',
  waits_for_somebody: true,
  plan_id: '01JPLAN',
  approval_id: '01JAPPR',
  steps: [{ name: 'safety_gate', outcome: 'passed' }, { name: 'playbook', outcome: 'timed_out' }],
  failure_class: 'timeout',
  failure_detail: 'The playbook did not finish within 1800 seconds.',
  requested_by: null,
  requested_at: '2026-09-09T08:00:00+00:00',
  started_at: '2026-09-09T08:00:01+00:00',
  finished_at: '2026-09-09T08:30:01+00:00',
}

const COMPLETED = { ...INDETERMINATE, id: '01JJOBDONE', server_name: 'redis-02', state: 'completed', waits_for_somebody: false, steps: [{ name: 'role:redis', outcome: 'passed' }], failure_class: null, failure_detail: null }

function stubFetch(onResolve?: (body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/resolve')) {
      onResolve?.(parsed)
      body = { data: { ...INDETERMINATE, state: parsed?.outcome, waits_for_somebody: false } }
    } else if (path.endsWith('/infrastructure/deployments')) {
      body = { data: [INDETERMINATE, COMPLETED], meta: { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 } }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({ ok: true, status: 200, statusText: '', headers: new Headers(), text: () => Promise.resolve(JSON.stringify(body)) } as Response)
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <DeploymentsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('DeploymentsPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('marks an indeterminate run as waiting for a person and resolves it only with a statement', async () => {
    const onResolve = vi.fn()
    vi.stubGlobal('fetch', stubFetch(onResolve))
    renderPage()

    const row = await screen.findByRole('row', { name: /redis-01/ })
    // The status word for indeterminate is "Outcome unknown": the state is
    // named for what it means, not for what the enum is called.
    expect(within(row).getByText(/^outcome unknown$/i)).toBeInTheDocument()
    expect(within(row).getByText(/waiting for a person/i)).toBeInTheDocument()

    await userEvent.click(within(row).getByRole('button', { name: /^open$/i }))
    expect(screen.getByText(/⏱ playbook/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /^resolve$/i }))

    const dialog = screen.getByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^resolve$/i })
    expect(confirm).toBeDisabled()

    await userEvent.click(within(dialog).getByLabelText(/the change is there/i))
    await userEvent.type(within(dialog).getByLabelText(/what did you see/i), 'redis-server is running and answers PING.')
    await userEvent.click(confirm)

    await waitFor(() => { expect(onResolve).toHaveBeenCalledWith({ outcome: 'completed', reason: 'redis-server is running and answers PING.' }); })
  })

  it('offers nothing on a completed run', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const row = await screen.findByRole('row', { name: /redis-02/ })
    await userEvent.click(within(row).getByRole('button', { name: /^open$/i }))

    expect(screen.queryByRole('button', { name: /^resolve$/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^cancel$/i })).not.toBeInTheDocument()
    expect(screen.getByText(/✓ role:redis/)).toBeInTheDocument()
  })
})
