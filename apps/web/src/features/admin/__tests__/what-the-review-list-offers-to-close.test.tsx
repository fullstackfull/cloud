import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { AdminProvisioningPage } from '@/features/admin/AdminProvisioningPage'
import '@/i18n'

/*
 * Which jobs waiting for a person the page offers to close (X9-1).
 *
 * A resize, a package change or a power change in review on a service that
 * has ended is refused by a retry and by an adoption; closing is its way out,
 * and the page offers it only where the server published the job `closable`.
 */

const BASE = {
  status: 'needs_review',
  customer_id: '01JCUSTOMER',
  failure_class: 'timeout',
  attempts: 5,
  provider_reference: null,
  reserved_provider_id: null,
  last_error: 'The machine could not be read back as the shape the resize asked for.',
  created_at: '2026-09-01T00:00:00+00:00',
}

const ENDED = { ...BASE, id: '01JENDEDRESIZE', kind: 'resize', closable: true }
const LIVE = { ...BASE, id: '01JLIVERESIZE', kind: 'resize', closable: false }

const META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch(posted: Array<{ path: string; body: unknown }>) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = (url.split('?')[0] ?? url).replace(/^https?:\/\/[^/]+/, '')

    let body: unknown

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/api/admin/provisioning/needs-review') || path.endsWith('/api/admin/provisioning/jobs')) {
      body = { data: [ENDED, LIVE], meta: META }
    } else if (path.endsWith('/close')) {
      posted.push({ path, body: typeof init?.body === 'string' ? JSON.parse(init.body) : null })
      body = { data: { id: ENDED.id, status: 'cancelled', service_id: null } }
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
        <AdminProvisioningPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

async function reviewRowOf(id: string): Promise<HTMLElement> {
  const text = await screen.findByText(new RegExp(`^${id} ·`))
  const row = text.closest('li')

  if (row === null) throw new Error(`No review row holds ${id}`)

  return row
}

describe('AdminProvisioningPage review list: close', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('offers to close only a job the server published as closable', async () => {
    vi.stubGlobal('fetch', stubFetch([]))
    renderPage()

    expect(within(await reviewRowOf(ENDED.id)).getByRole('button', { name: /^close the job$/i })).toBeInTheDocument()
    expect(within(await reviewRowOf(LIVE.id)).queryByRole('button', { name: /^close the job$/i })).not.toBeInTheDocument()
  })

  it('posts the close with the evidence to the job it was offered on', async () => {
    const posted: Array<{ path: string; body: unknown }> = []
    vi.stubGlobal('fetch', stubFetch(posted))
    renderPage()

    await userEvent.click(within(await reviewRowOf(ENDED.id)).getByRole('button', { name: /^close the job$/i }))
    expect(await screen.findByText(/takes this job off the review list without running it/i)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/what did you check/i), 'Service terminated on 1 Sept; machine destroyed.')
    const dialog = screen.getByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: /^close the job$/i }))

    await vi.waitFor(() => { expect(posted).toHaveLength(1) })
    expect(posted[0]?.path).toBe(`/api/admin/provisioning/jobs/${ENDED.id}/close`)
    expect(posted[0]?.body).toEqual({ evidence: 'Service terminated on 1 Sept; machine destroyed.' })
  })
})
