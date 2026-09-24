import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { OperatorsPage } from '@/features/controlCenter/OperatorsPage'
import '@/i18n'

/*
 * The screen that decides who may operate the platform. What it must get right
 * is not the happy path — it is that Super Admin does not read as a role with
 * no permissions, that an invitation never asks for a password, and that a
 * refusal reaches the operator in words rather than as a control that quietly
 * does nothing.
 */

const OPERATORS = [
  {
    id: '01JFIRST',
    name: 'Deployment Operator',
    email: 'ops@lynomia.test',
    roles: ['super-admin'],
    is_privileged: true,
    has_signed_in: true,
    two_factor_enabled: false,
    created_at: '2026-09-01T00:00:00+00:00',
  },
  {
    id: '01JSECOND',
    name: 'NOC Shift',
    email: 'noc@lynomia.test',
    roles: ['noc'],
    is_privileged: false,
    has_signed_in: false,
    two_factor_enabled: false,
    created_at: '2026-09-02T00:00:00+00:00',
  },
]

const ROLES = [
  { name: 'super-admin', label: 'Super Admin', is_staff_role: true, permissions_are_editable: false, grants_everything: true, permissions: [], operators: 1 },
  { name: 'noc', label: 'NOC', is_staff_role: true, permissions_are_editable: true, grants_everything: false, permissions: ['infrastructure.view', 'monitoring.view'], operators: 1 },
  { name: 'customer', label: 'Customer', is_staff_role: false, permissions_are_editable: true, grants_everything: false, permissions: ['catalog.view'], operators: 0 },
]

const META = { page: 1, per_page: 25, total: 2, last_page: 1, max_per_page: 100 }

function stubFetch(options: { rolesError?: { status: number; code: string; message: string }; capture?: (path: string, body: unknown) => void } = {}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = (url.split('?')[0] ?? url).replace(/^https?:\/\/[^/]+/, '')
    const parsed = typeof init?.body === 'string' ? JSON.parse(init.body) : null

    let body: unknown = null
    let status = 200

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.includes('/roles') && init?.method === 'PUT') {
      options.capture?.(path, parsed)

      if (options.rolesError !== undefined) {
        status = options.rolesError.status
        body = { error: { code: options.rolesError.code, message: options.rolesError.message } }
      } else {
        body = { data: OPERATORS[1] }
      }
    } else if (path.endsWith('/operators') && init?.method === 'POST') {
      options.capture?.(path, parsed)
      body = { data: { ...OPERATORS[1], email: parsed?.email } }
      status = 201
    } else if (path.endsWith('/operators')) {
      body = { data: OPERATORS, meta: META }
    } else if (path.endsWith('/roles')) {
      body = { data: ROLES }
    } else if (path.endsWith('/permissions')) {
      body = { data: Array.from({ length: 59 }, (_, i) => ({ name: `perm.${i}`, group: 'x', held_by_default_roles: [] })) }
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
        <OperatorsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('OperatorsPage', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('lists the people who operate the platform and who among them is privileged', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const first = await screen.findByRole('listitem', { name: /ops@lynomia.test/i })
    const second = await screen.findByRole('listitem', { name: /noc@lynomia.test/i })

    expect(within(first).getByText(/full administrator/i)).toBeInTheDocument()
    // The commonest confusion after adding somebody.
    expect(within(second).getByText(/has not signed in yet/i)).toBeInTheDocument()
  })

  it('does not describe Super Admin as a role with no permissions', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const role = await screen.findByRole('listitem', { name: /^Super Admin$/i })

    expect(within(role).getByText(/grants everything/i)).toBeInTheDocument()
    expect(within(role).queryByText(/0 permissions/i)).not.toBeInTheDocument()

    // And a role whose list does decide something says how long it is.
    const noc = await screen.findByRole('listitem', { name: /^NOC$/i })
    expect(within(noc).getByText(/2 permissions/i)).toBeInTheDocument()
  })

  it('offers only staff roles when changing what somebody may do', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const second = await screen.findByRole('listitem', { name: /noc@lynomia.test/i })

    expect(within(second).getByLabelText(/^NOC$/i)).toBeInTheDocument()
    // A customer login is not an operator, and the baseline role is not
    // something to hand out from this screen.
    expect(within(second).queryByLabelText(/^Customer$/i)).not.toBeInTheDocument()
  })

  it('shows the platform’s refusal in words rather than swallowing it', async () => {
    vi.stubGlobal('fetch', stubFetch({
      rolesError: {
        status: 422,
        code: 'rbac.last_administrator',
        message: 'That would leave the platform with no administrator. Give the role to somebody else first.',
      },
    }))
    renderPage()

    const first = await screen.findByRole('listitem', { name: /ops@lynomia.test/i })

    await userEvent.click(within(first).getByLabelText(/^NOC$/i))
    await userEvent.click(within(first).getByRole('button', { name: /save roles/i }))

    expect(await within(first).findByText(/no administrator/i)).toBeInTheDocument()
  })

  it('never asks for a password when adding an operator', async () => {
    const sent: { path: string; body: unknown }[] = []
    vi.stubGlobal('fetch', stubFetch({ capture: (path, body) => { sent.push({ path, body }) } }))
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /add an operator/i }))

    const form = await screen.findByRole('form', { name: /add an operator/i })

    expect(within(form).queryByLabelText(/password/i)).not.toBeInTheDocument()
    expect(within(form).getByText(/one-time link/i)).toBeInTheDocument()

    await userEvent.type(within(form).getByLabelText(/email address/i), 'third@lynomia.test')
    await userEvent.type(within(form).getByLabelText(/^name$/i), 'Third Operator')
    await userEvent.click(within(form).getByLabelText(/^NOC$/i))
    await userEvent.click(within(form).getByRole('button', { name: /add an operator/i }))

    await waitFor(() => { expect(sent).toHaveLength(1) })

    const body = sent[0]?.body as Record<string, unknown>

    expect(body.email).toBe('third@lynomia.test')
    expect(body.roles).toEqual(['noc'])
    expect(Object.keys(body)).not.toContain('password')
  })
})
