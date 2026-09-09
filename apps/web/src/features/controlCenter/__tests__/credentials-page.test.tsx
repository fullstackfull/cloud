import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { CredentialsPage } from '@/features/controlCenter/CredentialsPage'
import '@/i18n'

/*
 * The credential screen's contract with the operator, without a browser:
 * states are words rather than enum values, presence and state are separate
 * facts, a revocation cannot be sent without a reason, and the recorded
 * reference never appears anywhere on the page.
 */

const PRESENT = {
  id: '01JCREDPRESENT',
  name: 'registrar-key',
  purpose: 'Registrar API',
  environment: 'staging',
  backend: 'controller_environment',
  state: 'configured',
  usable: false,
  present: true,
  masked_hint: 'Q7X2',
  usage: { providers: 1, servers: 0 },
  last_tested_at: null,
  rotated_at: null,
  rotates_at: null,
  revoked_at: null,
  revoked_reason: null,
  notes: null,
  created_at: '2026-09-01T00:00:00+00:00',
}

const MISSING = { ...PRESENT, id: '01JCREDMISSING', name: 'bmc-password', state: 'missing', present: false, masked_hint: null, usage: { providers: 0, servers: 2 } }

const PAGE_META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch(onRevoke?: (body: unknown) => void, onRecord?: (body: unknown) => Response | undefined) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null
    let status = 200

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/revoke')) {
      onRevoke?.(parsed)
      body = { data: { ...MISSING, state: 'revoked', revoked_at: '2026-09-09T07:00:00+00:00', revoked_reason: parsed?.reason } }
    } else if (path.endsWith('/credentials') && init?.method === 'POST') {
      const custom = onRecord?.(parsed)
      if (custom !== undefined) return Promise.resolve(custom)
      body = { data: { ...PRESENT, id: '01JCREDNEW', name: parsed?.name, present: false, state: 'missing' } }
      status = 201
    } else if (path.endsWith('/credentials')) {
      body = { data: [MISSING, PRESENT], meta: PAGE_META }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: status < 400,
      status,
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
        <CredentialsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('CredentialsPage', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows presence and state as separate words, and never the reference', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const present = await screen.findByRole('row', { name: /registrar-key/i })
    expect(within(present).getByText(/^configured$/i)).toBeInTheDocument()
    expect(within(present).getByText(/on controller/i)).toBeInTheDocument()

    const missing = screen.getByRole('row', { name: /bmc-password/i })
    expect(within(missing).getByText(/^missing$/i)).toBeInTheDocument()
    expect(within(missing).getByText(/not on controller/i)).toBeInTheDocument()

    expect(screen.queryByText(/LYNOMIA_/)).not.toBeInTheDocument()
    expect(screen.queryByText(/backend_reference/)).not.toBeInTheDocument()
  })

  it('will not send a revocation without a reason', async () => {
    const onRevoke = vi.fn()
    vi.stubGlobal('fetch', stubFetch(onRevoke))
    renderPage()

    const missing = await screen.findByRole('row', { name: /bmc-password/i })
    await userEvent.click(within(missing).getByRole('button', { name: /^revoke$/i }))

    const dialog = screen.getByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^revoke$/i })
    expect(confirm).toBeDisabled()

    await userEvent.type(within(dialog).getByLabelText(/why\?/i), 'Chassis returned.')
    await userEvent.click(confirm)

    await waitFor(() => { expect(onRevoke).toHaveBeenCalledWith({ reason: 'Chassis returned.' }); })
  })

  it('records a reference with the backend named, and never a value field', async () => {
    const onRecord = vi.fn()
    vi.stubGlobal('fetch', stubFetch(undefined, (body) => { onRecord(body); return undefined }))
    renderPage()

    await screen.findByRole('row', { name: /registrar-key/i })
    await userEvent.click(screen.getByRole('button', { name: /record a credential/i }))

    const form = screen.getByRole('form', { name: /record where a credential lives/i })
    expect(within(form).getByText(/do not paste the secret/i)).toBeInTheDocument()

    await userEvent.type(within(form).getByLabelText(/^name$/i), 'backup-token')
    await userEvent.type(within(form).getByLabelText(/purpose/i), 'PBS')
    await userEvent.type(within(form).getByLabelText(/variable name on the controller/i), 'lynomia_pbs_token')
    await userEvent.click(within(form).getByRole('button', { name: /^record$/i }))

    await waitFor(() => { expect(onRecord).toHaveBeenCalled(); })
    const sent = onRecord.mock.calls[0]?.[0] as Record<string, unknown>
    expect(sent.backend).toBe('controller_environment')
    // Upper-cased on the way in: a reference is a variable name.
    expect(sent.backend_reference).toBe('LYNOMIA_PBS_TOKEN')
    expect(Object.keys(sent)).not.toContain('secret')
    expect(Object.keys(sent)).not.toContain('value')
  })
})
