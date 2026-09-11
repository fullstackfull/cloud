import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiTokensPage } from '@/features/tokens/ApiTokensPage'
import '@/i18n'

/**
 * The API token list: a revoke that asks first, a failed read that says so
 * instead of claiming there are no tokens, and a restrictions column that
 * reports what the platform actually enforces on the credential.
 *
 * Every field `ApiTokenResource` publishes is present on these fixtures, in
 * the shape it publishes them. A fixture that carries a subset compiles, and
 * then a column reading a field the fixture omits crashes on `undefined`
 * while the types say it cannot — which is exactly how the restrictions
 * column shipped its first draft.
 */

const TOKEN = {
  id: '01JTOKEN',
  name: 'deploy-bot',
  status: 'active',
  abilities: ['*'],
  // Null rather than [], because that is what the serialiser normalises an
  // empty list to: "from anywhere", one answer rather than two.
  allowed_ip_ranges: null,
  rate_limit_per_minute: null,
  last_used_at: null,
  last_used_ip: null,
  expires_at: null,
  revoked_at: null,
  revoked_reason: null,
  created_at: '2026-03-01T00:00:00+00:00',
}

const RESTRICTED = {
  ...TOKEN,
  id: '01JPINNED',
  name: 'build-server',
  allowed_ip_ranges: ['203.0.113.4/32', '198.51.100.0/24'],
  rate_limit_per_minute: 60,
  expires_at: '2026-06-01T00:00:00+00:00',
}

function stubFetch(routes: {
  list: { status: number; body: unknown }
  onRevoke?: () => void
}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let status = 200
    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      status = 204
    } else if (init?.method === 'DELETE' && path.includes('/me/api-tokens/')) {
      routes.onRevoke?.()
      status = 204
    } else if (path.endsWith('/me/api-tokens')) {
      status = routes.list.status
      body = routes.list.body
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

async function rowFor(name: string): Promise<HTMLElement> {
  const row = (await screen.findByText(name)).closest('tr')

  if (row === null) throw new Error(`No row for ${name}`)

  return row
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ApiTokensPage />
    </QueryClientProvider>,
  )
}

describe('the API tokens page', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('names the token in a confirmation and revokes nothing when the customer backs out', async () => {
    const revoked = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ list: { status: 200, body: { data: [TOKEN] } }, onRevoke: revoked }))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^revoke$/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/deploy-bot/)).toBeInTheDocument()

    await user.keyboard('{Escape}')

    expect(revoked).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: /^revoke$/i })).toBeInTheDocument()
  })

  it('revokes once when confirmed', async () => {
    const revoked = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ list: { status: 200, body: { data: [TOKEN] } }, onRevoke: revoked }))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^revoke$/i }))
    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: /^revoke token$/i })

    await user.click(confirm)
    await user.click(confirm)

    await waitFor(() => {
      expect(revoked).toHaveBeenCalledTimes(1)
    })
  })

  it('summarises what is actually enforced on each credential', async () => {
    /*
     * The question this column answers is "which of these is the one pinned to
     * the build server?", and until it existed the list could not tell a
     * customer apart from their own credentials. The abilities field is
     * deliberately not summarised: it holds the wildcard on every token and is
     * checked by nothing, so printing "full access" from it would be reading a
     * field as a promise.
     */
    vi.stubGlobal('fetch', stubFetch({ list: { status: 200, body: { data: [TOKEN, RESTRICTED] } } }))

    renderPage()

    const pinned = await rowFor('build-server')
    expect(within(pinned).getByText('2 addresses · 60/min')).toBeInTheDocument()

    const anywhere = await rowFor('deploy-bot')
    expect(within(anywhere).getByText('None')).toBeInTheDocument()

    // Not a promise the platform keeps: the abilities field holds the wildcard
    // on every token and is checked by nothing, so no row claims access from it.
    expect(screen.queryByText(/full access/i)).not.toBeInTheDocument()
    expect(screen.queryByText('*')).not.toBeInTheDocument()
  })

  it('reports a refused read instead of rendering "No tokens"', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        list: {
          status: 403,
          body: { error: { code: 'auth.forbidden', message: 'You are not permitted to perform this action.' } },
        },
      }),
    )

    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(/not permitted/i)
    expect(screen.queryByText(/^no tokens\.?$/i)).not.toBeInTheDocument()
  })
})
