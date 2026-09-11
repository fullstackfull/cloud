import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ToastProvider } from '@/components/Toasts'
import { WatchedOperationsProvider } from '@/features/operations/WatchedOperations'
import { useWatchOperations } from '@/features/operations/watchChannel'
import '@/i18n'
import type { AcceptedOperation, CustomerOperation, CustomerOperationState } from '@/lib/types'

/**
 * What the customer is told after they press a button, and for how long the
 * portal keeps asking.
 *
 * AR-12, AT-3, §51-56. The four claims asserted here are the four the wave
 * makes about asynchronous work:
 *
 *  - the acknowledgement says what happened — "Reboot requested" — and not
 *    "Success", which is a claim about a machine nobody has heard from;
 *  - the portal keeps asking while the work is unfinished and stops the moment
 *    the server calls it terminal;
 *  - `needs_review` and `indeterminate` are never reported as failures, and
 *    they point at support rather than at a retry;
 *  - a read that fails does not overwrite the last state the server gave.
 */

/** The 202 a power action returns, in the shape the API actually sends. */
function receipt(): AcceptedOperation {
  return {
    id: '01JOPERATION',
    service_id: '01JSERVICE',
    kind: 'restart',
    action: 'reboot',
    state: 'queued',
    is_terminal: false,
    needs_attention: false,
    retry_advice: 'wait',
    requested_at: new Date().toISOString(),
    finished_at: null,
  }
}

function operation(state: CustomerOperationState, pollAfterMs: number | null): CustomerOperation {
  const terminal = state !== 'queued' && state !== 'processing'

  return {
    id: '01JOPERATION',
    kind: 'restart',
    action: 'reboot',
    state,
    is_terminal: terminal,
    needs_attention: state === 'needs_review' || state === 'indeterminate' || state === 'failed',
    retry_advice: terminal ? 'support_required' : 'wait',
    failure_reason: null,
    resource: { service_id: '01JSERVICE' },
    requested_at: new Date().toISOString(),
    started_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    finished_at: terminal ? new Date().toISOString() : null,
    poll_after_ms: terminal ? null : pollAfterMs,
  }
}

/**
 * Serves the given reads in order, repeating the last one.
 *
 * Counting the calls is how "polling stops on terminal states" is asserted: it
 * is not enough that the screen stops changing, the requests have to stop.
 */
function serveReads(reads: CustomerOperation[]) {
  let served = 0

  const fetchMock = vi.fn((): Promise<Response> => {
    const body = reads[Math.min(served, reads.length - 1)]
    served += 1

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      text: () => Promise.resolve(JSON.stringify({ data: body })),
    } as Response)
  })

  return fetchMock
}

function Starter() {
  const { watch } = useWatchOperations()

  return (
    <button
      type="button"
      onClick={() => {
        watch(receipt(), {
          actionKey: 'operations.actions.reboot',
          href: '/vps/01JMACHINE',
          invalidate: ['vps'],
        })
      }}
    >
      Start
    </button>
  )
}

function mount() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <ToastProvider>
          <WatchedOperationsProvider>
            <Starter />
          </WatchedOperationsProvider>
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function pressStart(): void {
  fireEvent.click(screen.getByRole('button', { name: 'Start' }))
}

/** Let the clock run, flushing whatever React and the query client do with it. */
async function pass(ms: number): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms)
  })
}

describe('watching what was started', () => {
  beforeEach(() => {
    // The clock is under the test's control, because what is being asserted is
    // when the portal asks — and a test that waited in real seconds for a
    // three-second poll would be a slow test that still proved nothing about
    // the fifteen-minute window.
    vi.useFakeTimers()
    window.sessionStorage.clear()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('acknowledges the request in the lifecycle s own words', () => {
    vi.stubGlobal('fetch', serveReads([operation('processing', 3_000)]))

    mount()
    pressStart()

    /*
     * The exact sentence matters. "Reboot requested" is a statement about the
     * platform having the request; "Success" would be a statement about a
     * machine that has not been touched yet.
     */
    expect(screen.getByText('Reboot requested')).toBeInTheDocument()
    expect(screen.queryByText(/success/i)).not.toBeInTheDocument()
  })

  it('keeps asking while the work is unfinished and stops when it is over', async () => {
    const fetchMock = serveReads([
      operation('processing', 3_000),
      operation('processing', 3_000),
      operation('succeeded', null),
    ])

    vi.stubGlobal('fetch', fetchMock)

    mount()
    pressStart()

    /*
     * Enough for the reads that reach the terminal state — the server asked
     * for three seconds and the backoff widens it a little — and not so long
     * that the good news has cleared itself off the screen again.
     */
    await pass(10_000)

    expect(screen.getByText('Reboot completed')).toBeInTheDocument()

    const readsWhenSettled = fetchMock.mock.calls.length
    expect(readsWhenSettled).toBeGreaterThan(1)

    /*
     * The assertion the whole polling rule exists for. Five more minutes pass
     * and the portal does not ask again, because the server said there was
     * nothing left to wait for.
     */
    await pass(5 * 60_000)

    expect(fetchMock.mock.calls.length).toBe(readsWhenSettled)
  })

  it('never reports work waiting on a person as a failure', async () => {
    vi.stubGlobal('fetch', serveReads([operation('needs_review', null)]))

    mount()
    pressStart()
    await pass(5_000)

    // The honest sentence, and support rather than a retry: a customer told
    // this failed would press the button again on a half-built machine.
    expect(screen.getByText('Reboot stopped and we are looking at it')).toBeInTheDocument()
    expect(
      screen.getByText('Please contact support before trying again.'),
    ).toBeInTheDocument()
    expect(screen.queryByText('Reboot did not finish')).not.toBeInTheDocument()
  })

  it('never reports an unknown outcome as either a failure or a success', async () => {
    vi.stubGlobal('fetch', serveReads([operation('indeterminate', null)]))

    mount()
    pressStart()
    await pass(5_000)

    expect(screen.getByText('We could not confirm the result of Reboot')).toBeInTheDocument()
    expect(screen.queryByText('Reboot completed')).not.toBeInTheDocument()
    expect(screen.queryByText('Reboot did not finish')).not.toBeInTheDocument()

    // And the way out is support, because asking again is the one thing that
    // must not happen when the action may already have taken effect.
    expect(
      screen.getByText('Please contact support before trying again.'),
    ).toBeInTheDocument()
  })

  it('does not turn a failed read into a failed operation', async () => {
    /*
     * §54. The first read lands and says the work is running; every read after
     * it fails. What the customer must not be told is that their reboot
     * failed, because nothing of the sort has been reported.
     */
    let served = 0

    vi.stubGlobal(
      'fetch',
      vi.fn((): Promise<Response> => {
        served += 1

        if (served === 1) {
          return Promise.resolve({
            ok: true,
            status: 200,
            statusText: '',
            text: () => Promise.resolve(JSON.stringify({ data: operation('processing', 3_000) })),
          } as Response)
        }

        return Promise.reject(new TypeError('network down'))
      }),
    )

    mount()
    pressStart()

    /*
     * Long enough for the first poll and its retries to fail, and short enough
     * that the acknowledgement has not yet cleared itself — so what is on
     * screen is exactly what the platform last said.
     */
    await pass(5_000)

    expect(screen.queryByText('Reboot did not finish')).not.toBeInTheDocument()
    expect(screen.queryByText('Reboot completed')).not.toBeInTheDocument()

    // The acknowledgement is still the last thing the platform actually said.
    expect(screen.getByText('Reboot requested')).toBeInTheDocument()
  })
})
