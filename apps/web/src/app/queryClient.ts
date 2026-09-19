import { QueryClient } from '@tanstack/react-query'

import { ApiError } from '@/lib/api'
import { isOffline } from '@/lib/connection'

/**
 * The portal's one cache, and the two decisions in it that are about safety
 * rather than performance.
 *
 * In its own module so that the mutation-replay gate can build a client and
 * assert the defaults directly. A source-text check would not do: the value
 * that matters is a *library default*, so the dangerous configuration is the
 * one this file never mentions, and a test reading it as text would pass on
 * exactly the version that replays writes.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        refetchOnWindowFocus: false,

        /*
         * `offlineFirst` rather than the default `online`, for the same family
         * of reasons as the mutation setting below and the opposite mechanism.
         *
         * Under the default, a read started while the browser has no network
         * is *paused*: it never fires, never fails, and never resolves. What
         * the customer sees is a spinner, for as long as they care to look at
         * it — and if they navigated while offline, that spinner has replaced
         * a page they could previously read. "Loading" and "cannot load" are
         * different states and this collapsed them into the one that promises
         * something is coming.
         *
         * With `offlineFirst` the request is attempted, fails against the dead
         * network, and lands in the error state every screen already reports
         * through LoadFailure — "We could not reach the server" — with the
         * standing offline notice above it saying why. Two true sentences
         * instead of an indefinite spinner.
         *
         * This is a read-side setting and grants no licence to resend a write.
         */
        networkMode: 'offlineFirst',

        retry: (failureCount, error) => {
          // Client errors will not become correct by being repeated.
          if (error instanceof ApiError && error.status < 500) return false

          /*
           * And neither will anything, while there is no network to repeat it
           * into. Without this the query sits in a paused retry — which looks
           * exactly like the pending state this setting exists to avoid.
           */
          if (isOffline()) return false

          return failureCount < 2
        },
      },

      /*
       * Mutations are never retried and never replayed. Both halves matter and
       * only one of them is TanStack's default.
       *
       * `retry: false` is the default already, and is written out so that
       * nobody can turn a retry on without first deleting a comment explaining
       * why they must not. A retried mutation is a second reboot, a second
       * registration, a second payment attempt — and the request whose answer
       * was lost is precisely the one where repeating it is most likely to do
       * the thing twice.
       *
       * `networkMode: 'always'` is the half that is **not** the default, and
       * without it the portal replays mutations behind the customer's back.
       * Under the default `'online'`, a mutation fired while the browser is
       * offline is not attempted and not failed — it is *paused*, and
       * `QueryClient.mount()` subscribes to both the online manager and the
       * focus manager to call `resumePausedMutations()`. So a customer who
       * pressed "Reboot" in a lift, gave up, and later reopened the tab would
       * have rebooted their machine by regaining signal. Switching tabs is
       * enough; `refetchOnWindowFocus: false` does not help, because that flag
       * governs queries and the focus subscription resumes mutations anyway.
       *
       * With `'always'` the mutation is attempted immediately, fails against a
       * dead network, and is reported to the customer as a request that did
       * not get through. Nothing is queued, so there is nothing to resume.
       *
       * Reads are the opposite case and keep the default: refetching a GET on
       * reconnect costs nothing and is how a stale page becomes true again.
       */
      mutations: {
        retry: false,
        networkMode: 'always',
      },
    },
  })
}
