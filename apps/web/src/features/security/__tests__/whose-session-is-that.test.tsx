import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { LoginActivitySection } from '@/features/security/LoginActivitySection'
import { SessionsSection } from '@/features/security/SessionsSection'
import '@/i18n'

/**
 * The security page exists to answer one question — "is one of these not me?"
 *
 * It could not. The device column printed the raw user-agent header truncated
 * to the column width, and every desktop session a customer has begins with
 * the same sixty characters: `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)
 * AppleWebKit/537.36…`. Four identical-looking rows, one destructive button
 * each, and no way to aim it.
 *
 * These tests hold the replacement to both halves of being useful: it must
 * name the device well enough to pick one, and it must not name anything the
 * header does not actually say.
 */

const CURRENT = {
  id: '01JHERE',
  ip_address: '203.0.113.9',
  user_agent:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
  last_active_at: '2026-03-01T09:00:00+00:00',
  is_current: true,
}

const PHONE = {
  id: '01JPHONE',
  ip_address: '198.51.100.7',
  user_agent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
  last_active_at: '2026-02-28T18:30:00+00:00',
  is_current: false,
}

const SCRIPT = {
  id: '01JSCRIPT',
  ip_address: null,
  user_agent: 'curl/8.4.0',
  last_active_at: '2026-02-20T02:15:00+00:00',
  is_current: false,
}

function stubFetch(handlers: {
  sessions?: { status: number; body: unknown }
  activity?: { status: number; body: unknown }
  onRevoke?: (id: string) => void
}) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let status = 200
    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      status = 204
    } else if (init?.method === 'DELETE' && path.includes('/me/sessions/')) {
      handlers.onRevoke?.(path.split('/me/sessions/')[1] ?? '')
      status = 204
    } else if (path.endsWith('/me/sessions')) {
      status = handlers.sessions?.status ?? 200
      body = handlers.sessions?.body ?? { data: [] }
    } else if (path.endsWith('/me/login-activity')) {
      status = handlers.activity?.status ?? 200
      body = handlers.activity?.body ?? { data: [] }
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

  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

async function rowFor(text: string | RegExp): Promise<HTMLElement> {
  const row = (await screen.findByText(text)).closest('tr')

  if (row === null) throw new Error(`No row for ${String(text)}`)

  return row
}

describe('the sessions table', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('describes each session by browser and system rather than by header', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({ sessions: { status: 200, body: { data: [CURRENT, PHONE, SCRIPT] } } }),
    )

    renderIn(<SessionsSection />)

    expect(await screen.findByText('Chrome on macOS')).toBeInTheDocument()
    expect(screen.getByText('Safari on iPhone')).toBeInTheDocument()

    // The one string the parser cannot read says so, rather than being blank
    // or being guessed at.
    expect(screen.getByText('Unknown device')).toBeInTheDocument()

    // And no row shows the header itself.
    expect(screen.queryByText(/Mozilla\/5\.0/)).not.toBeInTheDocument()
    expect(screen.queryByText(/AppleWebKit/)).not.toBeInTheDocument()
  })

  it('keeps the header reachable for whoever has to quote it to support', async () => {
    vi.stubGlobal('fetch', stubFetch({ sessions: { status: 200, body: { data: [PHONE] } } }))

    renderIn(<SessionsSection />)

    // Available on demand, not rendered as the label. Hiding it entirely would
    // trade one problem for another.
    expect(await screen.findByTitle(PHONE.user_agent)).toBeInTheDocument()
  })

  it('invents no device model, no city and no coordinates', async () => {
    /*
     * A user agent is a claim the client makes — routinely frozen, spoofed and
     * reduced by the browsers themselves. A row reading "iPhone 14 Pro in
     * Kuwait City" would be inventing two facts to dress up a third, on the
     * one screen whose whole purpose is recognising what is not yours.
     */
    vi.stubGlobal('fetch', stubFetch({ sessions: { status: 200, body: { data: [PHONE] } } }))

    renderIn(<SessionsSection />)

    const row = await rowFor('Safari on iPhone')

    expect(row.textContent).not.toMatch(/\d{1,2} Pro|Pro Max|Galaxy|Pixel/)
    expect(row.textContent).not.toMatch(/Kuwait|City|°|latitude|longitude/i)
    // The IP is a fact the server recorded, so it stays.
    expect(row).toHaveTextContent('198.51.100.7')
  })

  it('names the device and the address in the sign-out confirmation', async () => {
    /*
     * The confirmation used to identify the session by its opaque id, which is
     * not a thing a customer has ever seen. Naming it the way the row names it
     * is what makes the second click a decision rather than a guess.
     */
    const revoked = vi.fn()
    vi.stubGlobal(
      'fetch',
      stubFetch({ sessions: { status: 200, body: { data: [CURRENT, PHONE] } }, onRevoke: revoked }),
    )
    const user = userEvent.setup()

    renderIn(<SessionsSection />)

    await user.click(await screen.findByRole('button', { name: /^sign out$/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Safari on iPhone/)).toBeInTheDocument()
    expect(within(dialog).getByText(/198\.51\.100\.7/)).toBeInTheDocument()
    expect(within(dialog).queryByText(/01JPHONE/)).not.toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: /sign out that device/i }))

    await waitFor(() => {
      expect(revoked).toHaveBeenCalledWith('01JPHONE')
    })
  })

  it('offers no sign-out button for the session doing the asking', async () => {
    vi.stubGlobal('fetch', stubFetch({ sessions: { status: 200, body: { data: [CURRENT] } } }))

    renderIn(<SessionsSection />)

    expect(await screen.findByText('Chrome on macOS')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^sign out$/i })).not.toBeInTheDocument()
  })

  it('reports a refused read instead of rendering "no sessions"', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        sessions: {
          status: 403,
          body: {
            error: { code: 'auth.forbidden', message: 'You are not permitted to perform this action.' },
          },
        },
      }),
    )

    renderIn(<SessionsSection />)

    expect(await screen.findByRole('alert')).toHaveTextContent(/not permitted/i)
    expect(screen.queryByText(/no sessions/i)).not.toBeInTheDocument()
  })
})

describe('the sign-in history', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('describes a sign-in with the same words the session list uses', async () => {
    /*
     * A customer comparing "a sign-in I do not recognise" against "a session I
     * do not recognise" is comparing two rows. Two vocabularies for the same
     * fact would make that comparison impossible, which is why both screens
     * read the same parser through the same hook.
     */
    vi.stubGlobal(
      'fetch',
      stubFetch({
        activity: {
          status: 200,
          body: {
            data: [
              {
                id: '01JFAIL',
                outcome: 'failed',
                ip_address: '198.51.100.7',
                user_agent: PHONE.user_agent,
                country: 'KW',
                occurred_at: '2026-02-28T18:29:00+00:00',
              },
            ],
          },
        },
      }),
    )

    renderIn(<LoginActivitySection />)

    expect(await screen.findByText('Safari on iPhone')).toBeInTheDocument()
    expect(screen.queryByText(/Mozilla\/5\.0/)).not.toBeInTheDocument()
  })

  it('shows failed attempts, which are the whole point of the list', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch({
        activity: {
          status: 200,
          body: {
            data: [
              {
                id: '01JFAIL',
                outcome: 'failed',
                ip_address: '198.51.100.7',
                user_agent: 'curl/8.4.0',
                country: null,
                occurred_at: '2026-02-28T18:29:00+00:00',
              },
            ],
          },
        },
      }),
    )

    renderIn(<LoginActivitySection />)

    // Translated, never the raw `failed` the server sent.
    const row = await rowFor('Unknown device')
    expect(row.textContent).not.toContain('failed')
  })
})
