import { useQuery, type UseQueryResult } from '@tanstack/react-query'

import { ApiError, NetworkError, api } from '@/lib/api'
import type { CustomerOperation, Envelope } from '@/lib/types'

/**
 * The one place the portal decides when to look at an operation again.
 *
 * AR-12 and §51-56. Before this there was one `refetchInterval` on the
 * WordPress list and nothing anywhere else: a reboot, a rebuild, a build and a
 * restore all returned a 202 and then the screen went quiet until somebody
 * reloaded it. The obvious fix — a `setInterval` in each screen that starts
 * something — is the one this module exists to prevent, because five screens
 * with their own timers is five sets of answers to the same five questions,
 * and the answers drift:
 *
 *  - **Poll only what is unfinished.** The interval comes from the server's
 *    own `poll_after_ms`, which is null the moment the state is terminal. A
 *    finished operation cannot be polled by a screen that gets its schedule
 *    from here, whatever the component does.
 *
 *  - **Back off.** A build takes minutes. The server's hint is a floor, and
 *    each successive read widens the gap up to a ceiling, so a tab left open
 *    on a stuck rebuild is not asking every three seconds an hour later.
 *
 *  - **Do not poll a tab nobody is looking at.** TanStack only runs an
 *    interval while the window has focus unless told otherwise, and it is not
 *    told otherwise here. A customer with fifteen tabs open is not fifteen
 *    pollers.
 *
 *  - **Look again when they come back.** Focus and reconnect both refetch, so
 *    returning to the tab shows the truth immediately rather than after the
 *    next tick. This is the one place `refetchOnWindowFocus` is turned on: the
 *    application default is off, which is right for a list and wrong for
 *    something in flight.
 *
 *  - **Stop asking eventually, without lying.** Past the observation window
 *    the polling stops and the caller is told the work is *taking longer than
 *    usual*. It is not reported as failed. The platform has not said it
 *    failed, and a screen that decides on its own that a rebuild failed is a
 *    screen that invites a customer to start a second one.
 *
 *  - **A read that fails is not an operation that failed.** §54. When a poll
 *    errors, the last state the server actually reported stays on screen and
 *    the failure is reported separately, as a failure to read. Overwriting
 *    "processing" with "something went wrong" because a request timed out is
 *    how a customer is told their server broke when their wifi dropped.
 *
 * There is no retry in here, and there is none anywhere else either. Retrying
 * is re-asking the product for the same thing, through that product's own
 * endpoint and its own idempotency key, on a deliberate press. A watcher that
 * could re-send a mutation would be a watcher that re-sends it after the read
 * that says "we do not know whether it happened".
 */

/** The server's hint is a floor; this is the ceiling the backoff climbs to. */
const MAX_INTERVAL_MS = 30_000

/**
 * How much longer to wait for each read already taken.
 *
 * Gentle on purpose: the first minute of a reboot is when somebody is actually
 * watching the screen, and a steep backoff would make exactly that minute feel
 * broken.
 */
const BACKOFF_PER_READ = 0.3

/**
 * After this long, stop asking and say it is taking longer than usual.
 *
 * Fifteen minutes is past the point where any of these operations is normal —
 * a reboot is seconds, a rebuild is minutes — and comfortably short of an
 * afternoon of polling by a tab somebody forgot.
 */
export const OBSERVATION_WINDOW_MS = 15 * 60_000

export interface WatchedOperation {
  /** The last state the server actually reported, or undefined before the first read. */
  operation: CustomerOperation | undefined
  /** True once the server says there is nothing left to wait for. */
  isSettled: boolean
  /**
   * True when the operation is still unfinished and has outrun the window.
   *
   * The work may yet finish; this says only that the portal has stopped
   * watching and the customer should be told so in those words.
   */
  isSlow: boolean
  /**
   * A read that did not come back.
   *
   * Reported beside `operation` rather than instead of it: the business state
   * is whatever the server last said, and a failed poll does not change it.
   */
  readError: ApiError | NetworkError | null
}

export function operationQueryKey(id: string): readonly unknown[] {
  return ['operations', id]
}

/**
 * How long to wait before reading this operation again, or false to stop.
 *
 * Exported because it is the rule, and a rule with branches deserves a unit
 * test that does not need a React tree to exercise it.
 */
export function nextPollDelay(
  operation: CustomerOperation | undefined,
  readsTaken: number,
  ageMs: number | null,
): number | false {
  // Nothing read yet: let the query's own first fetch happen, then decide.
  if (operation === undefined) return false

  // The server's own answer to "is there anything left to wait for".
  if (operation.poll_after_ms === null) return false

  if (ageMs !== null && ageMs > OBSERVATION_WINDOW_MS) return false

  return Math.min(operation.poll_after_ms * (1 + readsTaken * BACKOFF_PER_READ), MAX_INTERVAL_MS)
}

/**
 * How long this operation has been going, measured from the customer's press.
 *
 * `requested_at` rather than a timestamp taken when this hook mounted: the
 * window has to be a property of the operation, or a reload would restart the
 * clock on a rebuild that has been stuck for an hour and the screen would go
 * back to saying "working on it". Null before the first read, and null if the
 * server sent no timestamp, in which case the window simply does not apply
 * rather than applying from an invented start.
 */
export function ageOf(operation: CustomerOperation | undefined): number | null {
  if (operation?.requested_at === undefined || operation.requested_at === null) return null

  const requested = new Date(operation.requested_at).getTime()

  return Number.isFinite(requested) ? Date.now() - requested : null
}

/**
 * Watch one operation until it finishes, or until the window closes.
 *
 * Pass `null` to watch nothing — a screen that has not started anything calls
 * this unconditionally rather than conditionally calling a hook.
 */
export function useWatchedOperation(id: string | null): WatchedOperation {
  const query: UseQueryResult<CustomerOperation> = useQuery({
    queryKey: operationQueryKey(id ?? 'none'),
    enabled: id !== null,
    queryFn: async (): Promise<CustomerOperation> => {
      const response = await api.get<Envelope<CustomerOperation>>(
        `/operations/${encodeURIComponent(id ?? '')}`,
      )

      return response.data
    },

    /*
     * The schedule, and the only one in the portal for this.
     *
     * `dataUpdateCount` is how many times a read has actually landed, which is
     * what the backoff should climb with — not how long the component has been
     * mounted, and not how many renders React has done.
     */
    refetchInterval: (watched) =>
      nextPollDelay(watched.state.data, watched.state.dataUpdateCount, ageOf(watched.state.data)),

    // Coming back to the tab, or back online, is worth a read straight away.
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,

    /*
     * A poll that fails is retried a couple of times by the client before it
     * is called a read failure, because a single dropped request on a mobile
     * connection is not news. A 4xx is not retried: an operation that is gone
     * or forbidden will not reappear by being asked for again.
     */
    retry: (failureCount, error) => {
      if (error instanceof ApiError && error.status < 500) return false

      return failureCount < 2
    },
  })

  const operation = query.data
  const age = ageOf(operation)

  return {
    operation,
    isSettled: operation?.is_terminal ?? false,
    isSlow:
      operation !== undefined &&
      !operation.is_terminal &&
      age !== null &&
      age > OBSERVATION_WINDOW_MS,

    /*
     * Only ever a read failure. TanStack keeps the last successful `data` when
     * a refetch throws, so the state above is still the server's own answer
     * and this is strictly extra information about the connection.
     */
    readError:
      query.error instanceof ApiError || query.error instanceof NetworkError ? query.error : null,
  }
}
