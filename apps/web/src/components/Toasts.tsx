import { useCallback, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import {
  ToastContext,
  type Toast,
  type ToastChannel,
  type ToastTone,
} from '@/components/toastChannel'
import { cn } from '@/lib/cn'

/**
 * The portal's one channel for "something you started has moved on".
 *
 * AT-3. Feedback used to live wherever the control lived: a button's own
 * spinner, an inline alert inside the card, a sentence that disappeared when
 * the customer navigated. So a reboot started from a machine's page and
 * finished after the customer had moved to the invoices page finished
 * silently, and a rebuild that stopped for a person to look at said so on a
 * screen nobody was on.
 *
 * Three properties make this a channel rather than a component:
 *
 *  - **It is announced.** The list is a polite live region, so a screen reader
 *    hears "Reboot completed" without the focus moving. Polite, not assertive:
 *    an operation finishing is worth saying at the next pause, not worth
 *    interrupting the sentence somebody is reading.
 *
 *  - **It is keyed by what it is about.** `announce` takes a stable id — the
 *    operation's own id — and replacing a message with the same id updates it
 *    in place. A poll that lands four times while a rebuild runs produces one
 *    message that changes, not four stacked ones.
 *
 *  - **Bad news does not disappear.** An informational message clears itself
 *    after a few seconds. A warning or a failure stays until it is dismissed:
 *    the one class of message a customer must not miss is the one that says
 *    their machine needs somebody to look at it.
 */

/** How long a piece of good news stays on screen. */
const TRANSIENT_MS = 6_000

/** The same four tone families the alerts and badges read. */
const TONES: Record<ToastTone, string> = {
  info: 'border-[var(--tone-info-border)] bg-[var(--tone-info-surface)] text-[var(--tone-info-text)]',
  success:
    'border-[var(--tone-success-border)] bg-[var(--tone-success-surface)] text-[var(--tone-success-text)]',
  warning:
    'border-[var(--tone-warning-border)] bg-[var(--tone-warning-surface)] text-[var(--tone-warning-text)]',
  danger:
    'border-[var(--tone-danger-border)] bg-[var(--tone-danger-surface)] text-[var(--tone-danger-text)]',
}

/** Tones that clear themselves. A warning and a failure wait to be read. */
function clearsItself(tone: ToastTone): boolean {
  return tone === 'info' || tone === 'success'
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const [toasts, setToasts] = useState<Toast[]>([])

  /*
   * One timer per subject, so that a message replaced while its predecessor
   * was counting down does not inherit the old deadline — or get cleared by
   * it.
   */
  const timers = useRef(new Map<string, number>())

  const dismiss = useCallback((id: string) => {
    const timer = timers.current.get(id)

    if (timer !== undefined) {
      window.clearTimeout(timer)
      timers.current.delete(id)
    }

    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const announce = useCallback((toast: Toast) => {
    const existing = timers.current.get(toast.id)

    if (existing !== undefined) {
      window.clearTimeout(existing)
      timers.current.delete(toast.id)
    }

    setToasts((current) => {
      const without = current.filter((held) => held.id !== toast.id)

      // Newest last, which is also reading order in the live region.
      return [...without, toast]
    })

    if (clearsItself(toast.tone)) {
      timers.current.set(
        toast.id,
        window.setTimeout(() => {
          timers.current.delete(toast.id)
          setToasts((current) => current.filter((held) => held.id !== toast.id))
        }, TRANSIENT_MS),
      )
    }
  }, [])

  const channel = useMemo<ToastChannel>(() => ({ announce, dismiss }), [announce, dismiss])

  return (
    <ToastContext.Provider value={channel}>
      {children}

      {/*
        Fixed to the bottom on a phone and the bottom corner on a desktop, with
        `pointer-events-none` on the container so an empty channel cannot cover
        a control. Each message takes its own pointer events back.

        `end-6` rather than `right-6`: in Arabic the corner is the other one.
      */}
      <div
        className="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col gap-2 sm:inset-x-auto sm:end-6 sm:w-96"
        role="region"
        aria-label={t('operations.channel')}
      >
        {/*
          The live region is the list itself, and it is in the DOM whether or
          not it has children: a region announced only once it has content is a
          region assistive technology was not watching when the content
          arrived.
        */}
        <ol aria-live="polite" aria-atomic="false" className="flex flex-col gap-2">
          {toasts.map((toast) => (
            <li
              key={toast.id}
              className={cn(
                'pointer-events-auto rounded-lg border p-3 text-sm shadow-lg backdrop-blur',
                TONES[toast.tone],
              )}
            >
              <div className="flex items-start gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{toast.title}</p>

                  {toast.body === undefined ? null : <p className="mt-0.5">{toast.body}</p>}

                  {toast.action === undefined ? null : (
                    <Link
                      to={toast.action.to}
                      className="mt-1 inline-block font-medium underline"
                      onClick={() => { dismiss(toast.id); }}
                    >
                      {toast.action.label}
                    </Link>
                  )}
                </div>

                <button
                  type="button"
                  onClick={() => { dismiss(toast.id); }}
                  className="-m-1 rounded p-1 opacity-70 hover:opacity-100"
                >
                  <span className="sr-only">{t('operations.dismiss')}</span>
                  <svg viewBox="0 0 20 20" fill="currentColor" className="size-4" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                  </svg>
                </button>
              </div>
            </li>
          ))}
        </ol>
      </div>
    </ToastContext.Provider>
  )
}
