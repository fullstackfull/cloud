import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router'

import { ToastProvider } from '@/components/Toasts'
import { RolePermissionMatrix } from '@/features/team/RolePermissionMatrix'
import { TeamPage } from '@/features/team/TeamPage'
import '@/i18n'

/**
 * The audit's AS-12: an owner picked "Billing" from a dropdown and found out
 * what it meant by watching a colleague be refused. There was no statement
 * anywhere in the portal of what a role could do.
 *
 * The fix that would have been easy is a table written out in React. These
 * tests exist because that fix is the wrong one — a hand-written matrix is a
 * promise maintained in a file nobody edits when a permission moves — and they
 * assert the property that makes the real fix worth its cost: what the screen
 * renders comes from the server's own matrix, so a row cannot claim something
 * the API does not enforce.
 *
 * The two-directional proof lives in PHP, where both sets are readable:
 * `EveryPublishedCapabilityIsEnforcedTest`. What is asserted here is that the
 * screen renders that matrix rather than one of its own.
 */

const CAPABILITIES = [
  { id: 'view_services', permission: 'service.view' },
  { id: 'manage_services', permission: 'service.manage' },
  { id: 'view_billing', permission: 'billing.view' },
  { id: 'pay_invoices', permission: 'billing.pay' },
  { id: 'manage_members', permission: 'customer.members.manage' },
]

function role(id: string, granted: string[], extra: Record<string, unknown> = {}) {
  return {
    id,
    is_owner: id === 'owner',
    assignable: id !== 'owner',
    capabilities: CAPABILITIES.map((capability) => ({
      ...capability,
      granted: granted.includes(capability.id),
    })),
    ...extra,
  }
}

const MATRIX = {
  data: [
    role('owner', CAPABILITIES.map((capability) => capability.id)),
    role('administrator', ['view_services', 'manage_services', 'view_billing', 'pay_invoices', 'manage_members']),
    role('billing', ['view_services', 'view_billing', 'pay_invoices']),
    role('technical', ['view_services', 'manage_services']),
    role('member', ['view_services']),
  ],
  meta: { assignable_roles: ['administrator', 'billing', 'technical', 'member'] },
}

const OWNER = {
  id: '01JOWNER',
  name: 'Amal',
  email: 'amal@example.com',
  role: 'owner',
  invited_by: null,
  joined_at: '2026-01-01T00:00:00+00:00',
}

const COLLEAGUE = {
  id: '01JTECH',
  name: 'Bader',
  email: 'bader@example.com',
  role: 'technical',
  invited_by: 'Amal',
  joined_at: '2026-02-01T00:00:00+00:00',
}

interface Calls {
  roleChange?: (body: unknown) => void
}

function stubFetch(calls: Calls = {}, matrix: { status: number; body: unknown } = { status: 200, body: MATRIX }) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let status = 200
    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      status = 204
    } else if (path.endsWith('/team/roles')) {
      status = matrix.status
      body = matrix.body
    } else if (path.endsWith('/team/members')) {
      body = {
        data: [OWNER, COLLEAGUE],
        meta: { total: 2, limit: 50, assignable_roles: MATRIX.meta.assignable_roles },
      }
    } else if (path.endsWith('/team/invitations')) {
      body = { data: [], meta: { total: 0 } }
    } else if (path.includes('/team/members/') && init?.method === 'PATCH') {
      calls.roleChange?.(JSON.parse(typeof init.body === 'string' ? init.body : '{}'))
      body = { data: { ...COLLEAGUE, role: 'billing' } }
    } else if (path.endsWith('/me')) {
      body = {
        data: {
          id: '01JOWNER',
          name: 'Amal',
          email: 'amal@example.com',
          customers: [{ id: '01JACCOUNT', display_name: 'Northwind', role: 'owner' }],
        },
      }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: status >= 200 && status < 300,
      status,
      statusText: '',
      text: () => Promise.resolve(body === null ? '' : JSON.stringify(body)),
    } as Response)
  })
}

function renderIn(node: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <ToastProvider>{node}</ToastProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('the role permission matrix', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('renders the rows the server published rather than a list of its own', async () => {
    /*
     * The matrix served here deliberately omits four of the nine capabilities
     * the real endpoint publishes. If the screen were rendering its own table
     * those four would still appear — which is exactly the drift this design
     * exists to make impossible.
     */
    vi.stubGlobal('fetch', stubFetch())

    renderIn(<RolePermissionMatrix />)

    expect(await screen.findByText('See services')).toBeInTheDocument()
    expect(screen.getByText('Invite and manage people')).toBeInTheDocument()

    // Published by the real endpoint, absent from this response, absent here.
    expect(screen.queryByText(/api tokens/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/support/i)).not.toBeInTheDocument()
  })

  it('says yes and no in words, not only in a tick and a colour', async () => {
    /*
     * A cell whose meaning is carried by "✓" and a green means nothing to a
     * screen reader and nothing to a customer who does not see the colour. The
     * glyph stays for everyone else, but it is aria-hidden and never alone.
     */
    vi.stubGlobal('fetch', stubFetch())

    renderIn(<RolePermissionMatrix />)

    const row = (await screen.findByText('Pay invoices')).closest('tr')
    if (row === null) throw new Error('No row')

    // Owner, Administrator and Billing may pay; Technical and Member may not.
    expect(within(row).getAllByText('Yes')).toHaveLength(3)
    expect(within(row).getAllByText('No')).toHaveLength(2)

    for (const glyph of within(row).getAllByText(/^[✓—]$/)) {
      expect(glyph).toHaveAttribute('aria-hidden', 'true')
    }
  })

  it('reports a failed read rather than rendering an empty matrix', async () => {
    /*
     * An empty table under the heading "What each role can do" reads as "these
     * roles can do nothing", which is a worse answer than no table at all.
     */
    vi.stubGlobal(
      'fetch',
      stubFetch({}, {
        status: 500,
        body: { error: { code: 'server.error', message: 'Something went wrong on our side.' } },
      }),
    )

    renderIn(<RolePermissionMatrix />)

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})

describe('changing a colleague’s role', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('sends nothing on the change event and states what the person gains and loses', async () => {
    /*
     * The bare `<select onChange>` this replaced altered a colleague's access
     * on a stray scroll wheel, with no statement of what had happened. The
     * gains and losses are diffed from the server's matrix — the same source
     * the endpoint enforces from — so the dialogue describes real authorization
     * rather than a sentence somebody wrote about it.
     */
    const changed = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ roleChange: changed }))
    const user = userEvent.setup()

    renderIn(<TeamPage />)

    const row = (await screen.findByText('bader@example.com')).closest('tr')
    if (row === null) throw new Error('No row')

    await user.selectOptions(within(row).getByRole('combobox'), 'billing')

    expect(changed).not.toHaveBeenCalled()

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getAllByText(/Bader/).length).toBeGreaterThan(0)

    // Technical → Billing: gains the two billing capabilities, loses the one
    // service capability. Both halves are stated; neither is implied.
    expect(within(dialog).getByText('See billing')).toBeInTheDocument()
    expect(within(dialog).getByText('Pay invoices')).toBeInTheDocument()
    expect(within(dialog).getByText('Manage services')).toBeInTheDocument()
  })

  it('leaves the dropdown on the role the person still has until the server agrees', async () => {
    /*
     * An optimistic dropdown is a lie with a short half-life: it reads
     * "Billing" while the person is still Technical, and if the request is
     * refused it goes on reading "Billing" until something refetches.
     */
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()

    renderIn(<TeamPage />)

    const row = (await screen.findByText('bader@example.com')).closest('tr')
    if (row === null) throw new Error('No row')

    const select = within(row).getByRole('combobox')
    await user.selectOptions(select, 'billing')

    await screen.findByRole('dialog')
    expect(select).toHaveValue('technical')
  })

  it('does not ask for a typed phrase, because the change is reversible', async () => {
    /*
     * The confirmation policy is graded. Typing a name is reserved for things
     * that cannot be undone; asking for it here would teach customers to type
     * words into boxes to get on with their day, which is how a typed
     * confirmation stops being read.
     */
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()

    renderIn(<TeamPage />)

    const row = (await screen.findByText('bader@example.com')).closest('tr')
    if (row === null) throw new Error('No row')

    await user.selectOptions(within(row).getByRole('combobox'), 'billing')

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('warns when the new role can remove the person granting it', async () => {
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()

    renderIn(<TeamPage />)

    const row = (await screen.findByText('bader@example.com')).closest('tr')
    if (row === null) throw new Error('No row')

    await user.selectOptions(within(row).getByRole('combobox'), 'administrator')

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/including you/i)).toBeInTheDocument()
  })

  it('sends the change exactly once when it is confirmed', async () => {
    const changed = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ roleChange: changed }))
    const user = userEvent.setup()

    renderIn(<TeamPage />)

    const row = (await screen.findByText('bader@example.com')).closest('tr')
    if (row === null) throw new Error('No row')

    await user.selectOptions(within(row).getByRole('combobox'), 'billing')

    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /change the role/i })

    await user.click(confirm)
    await user.click(confirm)

    await waitFor(() => {
      expect(changed).toHaveBeenCalledTimes(1)
    })
    expect(changed).toHaveBeenCalledWith({ role: 'billing' })
  })
})
