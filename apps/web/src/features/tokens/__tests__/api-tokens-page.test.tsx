import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiTokensPage } from '@/features/tokens/ApiTokensPage'
import '@/i18n'

/**
 * The API token list: a revoke that asks first, and a failed read that says
 * so instead of claiming there are no tokens.
 */

const TOKEN = {
  id: '01JTOKEN',
  name: 'deploy-bot',
  status: 'active',
  last_used_at: null,
  last_used_ip: null,
  revoked_at: null,
  created_at: '2026-03-01T00:00:00+00:00',
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
