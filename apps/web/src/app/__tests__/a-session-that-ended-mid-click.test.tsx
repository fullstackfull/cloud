import { QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createQueryClient } from '@/app/queryClient'
import { ConnectionNotice } from '@/app/ConnectionNotice'
import { SessionExpiryNotice } from '@/app/SessionExpiryNotice'
import '@/i18n'
import { useVpsPower } from '@/lib/queries'

/**
 * §35–§37. The three ways a request can fail to happen, and the one rule that
 * governs all of them: **the portal never sends a write the customer did not
 * just ask for.**
 *
 * A refusal, a dead network and an ended session look identical to a component
 * — all three are a rejected promise — and they call for three different
 * sentences and exactly one behaviour. The sentences are easy to get wrong and
 * the behaviour is easy to get wrong *invisibly*, which is why the mutation
 * case here counts requests rather than reading the screen.
 *
 * The mutation under test is a real one: `POST /vps/{id}/power`, wired through
 * the real `useVpsPower` against the real `QueryClient` the application
 * builds. Nothing about the hook is stubbed. A test that mocked the mutation
 * would be asserting that a mock does not fire twice.
 */

const POWER = '/api/v1/vps/01JMACHINE/power'

/** Every request the page made, in order, so replays are countable. */
let sent: Array<{ url: string; method: string }> = []

/** What the next API call should do. */
let answer: 'ok' | 'expired' | 'offline' | 'refused' = 'ok'

function stubFetch() {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const method = init?.method ?? 'GET'

    if (url.includes('/sanctum/csrf-cookie')) {
      return Promise.resolve(response(204, null))
    }

    sent.push({ url, method })

    if (url.includes('/me')) {
      return Promise.resolve(
        answer === 'expired'
          ? response(401, { error: { code: 'auth.unauthenticated', message: 'Please sign in to continue.' } })
          : response(200, { data: signedIn }),
      )
    }

    if (answer === 'offline') {
      // What a dead network actually looks like to fetch: a rejection with no
      // status at all, not an HTTP error.
      return Promise.reject(new TypeError('Failed to fetch'))
    }

    if (answer === 'expired') {
      return Promise.resolve(
        response(401, { error: { code: 'auth.unauthenticated', message: 'Please sign in to continue.' } }),
      )
    }

    if (answer === 'refused') {
      return Promise.resolve(
        response(422, {
          error: { code: 'vps.power.blocked', message: 'This machine is being rebuilt.' },
        }),
      )
    }

    return Promise.resolve(response(202, { data: { id: '01JOP', status: 'queued' } }))
  })
}

const signedIn = {
  id: '01JUSER',
  name: 'Amal',
  email: 'amal@example.com',
  email_verified: true,
  locale: 'en',
  timezone: 'Asia/Kuwait',
  phone: null,
  two_factor_enabled: false,
  last_login_at: null,
  created_at: null,
  permissions: [],
  customers: [],
}

function response(status: number, body: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: '',
    text: () => Promise.resolve(body === null ? '' : JSON.stringify(body)),
  } as Response
}

/**
 * The button a customer presses, wired to the real power mutation.
 *
 * A fresh idempotency key per press, which is how the portal does it
 * everywhere: the key protects one press from being counted twice by the
 * server, and it is emphatically not a licence for the client to send the same
 * press again on its own.
 */
function RebootButton() {
  const power = useVpsPower()
  const [failed, setFailed] = useState(false)
  const [presses, setPresses] = useState(0)

  return (
    <div>
      <button
        type="button"
        onClick={() => {
          setFailed(false)
          setPresses((count) => count + 1)
          power.mutate(
            { id: '01JMACHINE', action: 'reboot', idempotencyKey: `press-${String(presses)}` },
            { onError: () => { setFailed(true); } },
          )
        }}
      >
        Reboot
      </button>
      {failed ? <p role="alert">The reboot did not go through.</p> : null}
    </div>
  )
}

function renderPage() {
  const client = createQueryClient()

  return {
    client,
    ...render(
      <MemoryRouter initialEntries={['/vps/01JMACHINE']}>
        <QueryClientProvider client={client}>
          <ConnectionNotice />
          <RebootButton />
          <SessionExpiryNotice />
        </QueryClientProvider>
      </MemoryRouter>,
    ),
  }
}

function powerRequests(): number {
  return sent.filter((request) => request.url.includes(POWER) && request.method === 'POST').length
}

/** Tell the browser, and therefore TanStack, that the network is back. */
async function reconnect() {
  Object.defineProperty(navigator, 'onLine', { value: true, configurable: true })

  await act(async () => {
    window.dispatchEvent(new Event('online'))
    await Promise.resolve()
  })
}

async function goOffline() {
  Object.defineProperty(navigator, 'onLine', { value: false, configurable: true })

  await act(async () => {
    window.dispatchEvent(new Event('offline'))
    await Promise.resolve()
  })
}

describe('a mutation that met an expired session', () => {
  beforeEach(() => {
    sent = []
    answer = 'ok'
    vi.stubGlobal('fetch', stubFetch())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('tells the customer their session ended rather than that the action failed', async () => {
    const user = userEvent.setup()
    renderPage()

    // The profile read establishes that somebody *was* signed in — without it
    // a 401 is just the normal way of discovering nobody is.
    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    answer = 'expired'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))

    expect(await screen.findByRole('alertdialog')).toBeInTheDocument()
    expect(screen.getByText(/you have been signed out/i)).toBeInTheDocument()
  })

  it('says out loud that nothing was repeated', async () => {
    /*
     * The part a customer would otherwise assume the other way round. A
     * half-finished reboot, payment or renewal leaves them wondering whether
     * signing back in will finish the job; the dialogue answers it before they
     * ask.
     */
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    answer = 'expired'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))

    await screen.findByRole('alertdialog')
    expect(screen.getByText(/nothing you had started has been repeated/i)).toBeInTheDocument()
  })

  it('NEVER replays the write after the session comes back', async () => {
    /*
     * The mandatory proof, and the reason this file counts requests instead of
     * reading the screen: a replay is silent. The customer sees a dialogue,
     * signs in, and the machine reboots — which looks like the thing they
     * asked for, because it is the thing they asked for, five minutes ago,
     * under a session that no longer existed.
     *
     * `POST /vps/{id}/power` was chosen deliberately over something harmless.
     * A reboot is idempotent in shape and emphatically not in effect: doing it
     * twice takes a production machine down a second time, and the second one
     * is the one nobody is expecting.
     */
    const user = userEvent.setup()
    const { client } = renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    answer = 'expired'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))
    await screen.findByRole('alertdialog')

    expect(powerRequests()).toBe(1)

    // Now everything that could plausibly resurrect it: the session returns,
    // the profile is refetched, the window is focused, the network blips, and
    // the client is explicitly asked to resume whatever it was holding.
    answer = 'ok'

    await act(async () => {
      await client.refetchQueries({ queryKey: ['auth', 'me'] })
    })

    await act(async () => {
      window.dispatchEvent(new Event('focus'))
      await Promise.resolve()
    })

    await reconnect()

    await act(async () => {
      await client.resumePausedMutations()
    })

    // Still one. The customer's press is the only thing that sends a reboot.
    expect(powerRequests()).toBe(1)
  })

  it('sends exactly one more when the customer asks again, deliberately', async () => {
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    answer = 'expired'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))
    await screen.findByRole('alertdialog')
    expect(powerRequests()).toBe(1)

    answer = 'ok'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))

    await waitFor(() => {
      expect(powerRequests()).toBe(2)
    })
  })
})

describe('a read that met an expired session', () => {
  beforeEach(() => {
    sent = []
    answer = 'ok'
    vi.stubGlobal('fetch', stubFetch())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers a way back to the page they were on', async () => {
    /*
     * Reads are the case where returning *is* the right behaviour: re-asking a
     * GET costs nothing and is how the page becomes true again. The route
     * travels so that signing in lands them where they were rather than on the
     * dashboard.
     */
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    answer = 'expired'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))
    await screen.findByRole('alertdialog')

    const signIn = screen.getByRole('link', { name: /sign in/i })
    const href = signIn.getAttribute('href') ?? ''

    expect(href).toContain('/sign-in')
    expect(href).toContain(encodeURIComponent('/vps/01JMACHINE'))
  })

  it('lets the customer keep reading the page they already have', async () => {
    // Dismissing does not sign them back in and does not pretend to. What is
    // already on screen is still true; it simply will not update.
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    answer = 'expired'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))
    await screen.findByRole('alertdialog')

    await user.click(screen.getByRole('button', { name: /keep reading/i }))

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  })
})

describe('a mutation that met a dead network', () => {
  beforeEach(() => {
    sent = []
    answer = 'ok'
    vi.stubGlobal('fetch', stubFetch())
  })

  afterEach(async () => {
    vi.unstubAllGlobals()
    await reconnect()
  })

  it('is attempted and failed rather than queued', async () => {
    /*
     * This is the assertion that fails under TanStack's default
     * `networkMode: 'online'`. There, `mutate()` while offline does not call
     * fetch at all — the mutation is *paused* — and the request count here
     * would be 0, with the write sitting in the cache waiting for a reconnect
     * or a window focus to fire it.
     */
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    await goOffline()
    answer = 'offline'

    await user.click(screen.getByRole('button', { name: 'Reboot' }))

    await waitFor(() => {
      expect(powerRequests()).toBe(1)
    })

    expect(await screen.findByRole('alert')).toHaveTextContent(/did not go through/i)
  })

  it('NEVER fires again when the network comes back', async () => {
    const user = userEvent.setup()
    const { client } = renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    await goOffline()
    answer = 'offline'

    await user.click(screen.getByRole('button', { name: 'Reboot' }))
    await waitFor(() => {
      expect(powerRequests()).toBe(1)
    })

    answer = 'ok'
    await reconnect()

    await act(async () => {
      window.dispatchEvent(new Event('focus'))
      await client.resumePausedMutations()
    })

    // The customer pressed once, in a lift, and gave up. Coming out of the
    // lift must not reboot their machine.
    expect(powerRequests()).toBe(1)
  })

  it('says the network is gone, not that the platform refused', async () => {
    renderPage()
    await goOffline()

    expect(await screen.findByText(/you are offline/i)).toBeInTheDocument()
  })

  it('stops saying so once the network is back', async () => {
    renderPage()
    await goOffline()
    expect(await screen.findByText(/you are offline/i)).toBeInTheDocument()

    await reconnect()

    await waitFor(() => {
      expect(screen.queryByText(/you are offline/i)).not.toBeInTheDocument()
    })
  })
})

describe('a mutation the platform refused', () => {
  beforeEach(() => {
    sent = []
    answer = 'ok'
    vi.stubGlobal('fetch', stubFetch())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('is a business answer, not a session problem and not a network problem', async () => {
    /*
     * The third of the three. A 422 is the platform having considered the
     * request and said no — the machine is being rebuilt — and it must not
     * raise the expiry dialogue, must not raise the offline notice, and must
     * not be retried, because repeating it will produce the same no.
     */
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(sent.some((request) => request.url.includes('/me'))).toBe(true)
    })

    answer = 'refused'
    await user.click(screen.getByRole('button', { name: 'Reboot' }))

    await waitFor(() => {
      expect(powerRequests()).toBe(1)
    })

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(screen.queryByText(/you are offline/i)).not.toBeInTheDocument()

    // And it stays at one: a refusal is not a thing to try again by itself.
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(powerRequests()).toBe(1)
  })
})
