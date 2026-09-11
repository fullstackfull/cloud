import { useQueryClient } from '@tanstack/react-query'
import type { TFunction } from 'i18next'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { useToasts, type ToastChannel } from '@/components/toastChannel'
import {
  WatchContext,
  type OperationWatch,
  type WatchChannel,
  type WatchableReceipt,
} from '@/features/operations/watchChannel'
import type { CustomerOperationState } from '@/lib/types'
import { useWatchedOperation } from '@/lib/watchOperation'
import i18n from '@/i18n'

/**
 * What the portal is currently watching, and what it says about it.
 *
 * §16, §20 and §51. A 202 used to be the end of the story: the button stopped
 * spinning, the card said nothing, and the customer found out what had
 * happened by reloading. This is the other half — one list of things in
 * flight, one watcher each, and one channel they all speak through.
 *
 * ---------------------------------------------------------------------------
 * Why the list is not component state
 * ---------------------------------------------------------------------------
 *
 * Two of the wave's requirements are about what happens when the customer
 * stops looking at the screen they pressed the button on. A reboot started
 * from a machine's page and watched from component state stops being watched
 * the moment they navigate to their invoices, and stops existing entirely if
 * they reload. So the list lives here, above the routes, and it is written to
 * `sessionStorage` so that a reload resumes watching rather than forgetting.
 *
 * What is stored is an id and a label — never a state. The state is always
 * whatever the server says on the next read, because a state cached in the
 * browser is a second truth, and a stale one.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * Nothing in this module can send a mutation. Watching is reading. Retrying is
 * re-asking the product through its own endpoint, on a deliberate press, with
 * a new idempotency key — and a watcher that could do it automatically would
 * eventually do it in response to the one read that says "we do not know
 * whether this happened".
 */

/**
 * How many things one browser tab will watch at once.
 *
 * Small on purpose. A customer rebooting six machines is watching six
 * operations; a bug that pushed a watch on every render would otherwise be a
 * polling storm, and the cap turns that into a bounded one.
 */
const MAX_WATCHED = 6

const STORAGE_KEY = 'lynomia.watching'

function readStored(): OperationWatch[] {
  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY)
    if (raw === null) return []

    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []

    return parsed
      .filter(
        (entry): entry is OperationWatch =>
          typeof entry === 'object' &&
          entry !== null &&
          typeof (entry as OperationWatch).id === 'string' &&
          typeof (entry as OperationWatch).actionKey === 'string' &&
          /*
           * W5.7. The shape check is not enough: `actionKey` is rendered
           * through `t()`, and session storage is a place a customer can edit
           * and an older build can leave things. A key the catalogue does not
           * carry would put `vps.actions.stop` — or whatever somebody typed —
           * into the title of a message about their machine.
           *
           * A watch that cannot be described is dropped rather than
           * described badly. It costs the resumption of one operation across
           * a reload; the state itself is on the server and the resource's
           * own page reads it fresh.
           */
          i18n.exists((entry as OperationWatch).actionKey),
      )
      .slice(0, MAX_WATCHED)
  } catch {
    // Private browsing, blocked site data, or something else's key in ours.
    // Watching nothing is the right answer; the states are all on the server.
    return []
  }
}

function store(watches: OperationWatch[]): void {
  try {
    window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(watches))
  } catch {
    // Storage being unavailable costs resumption across a reload and nothing
    // else. It is not worth a message to the customer.
  }
}

export function WatchedOperationsProvider({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const toasts = useToasts()
  const [watches, setWatches] = useState<OperationWatch[]>(readStored)

  const forget = useCallback((id: string) => {
    setWatches((current) => {
      const next = current.filter((held) => held.id !== id)
      store(next)

      return next
    })
  }, [])

  const watch = useCallback(
    (receipt: WatchableReceipt, options: Omit<OperationWatch, 'id'>) => {
      const label = t(options.actionKey)

      /*
       * The acknowledgement, immediately, and in the lifecycle's own words.
       * `requested` is true of every 202 the platform issues — it says the
       * platform has the request, which is exactly as much as anybody knows.
       */
      toasts.announce({
        id: receipt.id,
        tone: 'info',
        title: t('operations.requested', { action: label }),
        ...(options.href === undefined
          ? {}
          : { action: { label: t('operations.view'), to: options.href } }),
      })

      /*
       * An operation that is already finished — a replayed idempotency key on
       * work that completed while the response was in flight — is announced
       * and not watched. Polling something the server has already called
       * terminal is the thing the poll hint exists to prevent.
       */
      if (receipt.is_terminal) {
        announceTerminal(receipt.state, {
          id: receipt.id,
          label,
          href: options.href,
          toasts,
          t,
        })

        return
      }

      setWatches((current) => {
        const without = current.filter((held) => held.id !== receipt.id)
        const next = [...without, { id: receipt.id, ...options }].slice(-MAX_WATCHED)
        store(next)

        return next
      })
    },
    [t, toasts],
  )

  const acknowledge = useCallback(
    (subject: string, options: Omit<OperationWatch, 'id'>) => {
      toasts.announce({
        id: subject,
        tone: 'info',
        title: t('operations.requested', { action: t(options.actionKey) }),
        ...(options.href === undefined
          ? {}
          : { action: { label: t('operations.view'), to: options.href } }),
      })
    },
    [t, toasts],
  )

  const channel = useMemo<WatchChannel>(() => ({ watch, acknowledge }), [watch, acknowledge])

  return (
    <WatchContext.Provider value={channel}>
      {children}

      {/*
        One watcher per operation, and a component rather than a loop of hooks
        because that is how React lets a variable number of subscriptions
        exist. Each renders nothing.
      */}
      {watches.map((held) => (
        <OperationWatcher key={held.id} watch={held} onFinished={forget} />
      ))}
    </WatchContext.Provider>
  )
}

/**
 * The message a finished operation deserves, by state.
 *
 * A `switch` with a case for every member of the union, so that a state added
 * to the API cannot reach a customer as whichever message happened to be the
 * fallback. In particular there is no arm that turns `needs_review` or
 * `indeterminate` into a failure: one says a person at Lynomia is looking, the
 * other says nobody knows yet, and both are the truth rather than the nearest
 * familiar word.
 */
function announceTerminal(
  state: CustomerOperationState,
  context: {
    id: string
    label: string
    href: string | undefined
    toasts: ToastChannel
    t: TFunction
  },
): void {
  const { id, label, href, toasts, t } = context

  const view = href === undefined ? {} : { action: { label: t('operations.view'), to: href } }

  switch (state) {
    case 'succeeded':
      toasts.announce({
        id,
        tone: 'success',
        title: t('operations.succeeded', { action: label }),
        ...view,
      })

      return

    case 'failed':
      toasts.announce({
        id,
        tone: 'danger',
        title: t('operations.failed', { action: label }),
        body: t('operations.retryAdvice.safe_to_retry'),
        ...view,
      })

      return

    case 'needs_review':
      toasts.announce({
        id,
        tone: 'warning',
        title: t('operations.needsReview', { action: label }),
        body: t('operations.retryAdvice.support_required'),
        ...view,
      })

      return

    case 'indeterminate':
      toasts.announce({
        id,
        tone: 'warning',
        title: t('operations.indeterminate', { action: label }),
        body: t('operations.retryAdvice.support_required'),
        ...view,
      })

      return

    case 'cancelled':
      toasts.announce({
        id,
        tone: 'info',
        title: t('operations.cancelled', { action: label }),
        ...view,
      })

      return

    /*
     * Not terminal, so not reachable from either caller — but written out
     * rather than defaulted, because that is what makes the compiler complain
     * when an eighth state appears.
     */
    case 'queued':
    case 'processing':
      return
  }
}

function OperationWatcher({
  watch,
  onFinished,
}: {
  watch: OperationWatch
  onFinished: (id: string) => void
}) {
  const { t } = useTranslation()
  const toasts = useToasts()
  const queryClient = useQueryClient()
  const { operation, isSlow } = useWatchedOperation(watch.id)

  /*
   * What has already been said about this operation.
   *
   * Keyed by the operation's identity and holding the last thing announced, so
   * that four polls of a running rebuild do not produce four messages, and so
   * that the terminal message is announced once even if a later read of the
   * same terminal state lands.
   */
  const announced = useRef<string | null>(null)

  useEffect(() => {
    if (operation === undefined) return

    const label = t(watch.actionKey)

    if (isSlow && !operation.is_terminal) {
      if (announced.current === 'slow') return
      announced.current = 'slow'

      /*
       * §55. Not "it failed". The platform has not said that, and this message
       * is about the portal having stopped watching rather than about the work
       * having stopped. The resource keeps its own page, where the state is
       * read fresh.
       */
      toasts.announce({
        id: watch.id,
        tone: 'warning',
        title: t('operations.slow', { action: label }),
        body: t('operations.slowBody'),
        ...(watch.href === undefined
          ? {}
          : { action: { label: t('operations.view'), to: watch.href } }),
      })

      onFinished(watch.id)

      return
    }

    if (!operation.is_terminal) return
    if (announced.current === operation.state) return

    announced.current = operation.state

    announceTerminal(operation.state, {
      id: watch.id,
      label,
      href: watch.href,
      toasts,
      t,
    })

    /*
     * Now that the work has landed, the screens that show its result are
     * stale. Refreshed here rather than by each screen, because the screen
     * that pressed the button may not be the screen that is open.
     */
    for (const family of watch.invalidate ?? []) {
      void queryClient.invalidateQueries({ queryKey: [family] })
    }

    onFinished(watch.id)
  }, [operation, isSlow, watch, toasts, t, queryClient, onFinished])

  return null
}
