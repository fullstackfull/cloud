import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/Button'
import { LocaleSwitcher } from '@/components/LocaleSwitcher'
import { cn } from '@/lib/cn'

import { NavGroups } from './NavGroups'
import { CUSTOMER_NAV_GROUPS, OPERATOR_NAV_GROUPS } from './navigation'

interface MobileNavigationProps {
  open: boolean
  isOperator: boolean
  signingOut: boolean
  onClose: () => void
  onSignOut: () => void
}

/**
 * The phone's navigation: every destination, in a drawer that owns the screen
 * while it is open.
 *
 * Built on the native `<dialog>` element, like the confirmation dialogs, so
 * the browser owns the focus trap, the inertness of the page behind, the
 * Escape key and the accessibility tree. The earlier drawer was a `<nav>`
 * pushed into the header: it listed four of twenty-one destinations, could
 * not scroll, and left the page behind it fully interactive. This one reads
 * the same list the desktop bar does (see navigation.ts), scrolls when the
 * viewport is shorter than the list, closes on Escape, on the backdrop, and
 * on choosing a destination, and carries the language switch and sign-out so
 * that nothing a customer needs is only on the desktop.
 *
 * Anchored to the start edge — the left in English, the right in Arabic —
 * by logical margins, so the drawer opens from the side the hamburger is on.
 */
export function MobileNavigation({ open, isOperator, signingOut, onClose, onSignOut }: MobileNavigationProps) {
  const { t } = useTranslation()
  const dialog = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const element = dialog.current
    if (element === null) return

    if (open && !element.open) {
      element.showModal()
    } else if (!open && element.open) {
      element.close()
    }
  }, [open])

  return (
    <dialog
      ref={dialog}
      aria-label={t('nav.menu')}
      className={cn(
        'm-0 me-auto mb-auto h-dvh max-h-dvh w-[min(20rem,85vw)] max-w-none',
        'flex-col overflow-y-auto bg-[var(--surface-raised)] p-0 text-[var(--text-primary)] shadow-xl',
        'backdrop:bg-black/40 open:flex',
      )}
      // Escape fires `cancel`; the state must follow the element or the next
      // press of the hamburger would try to open a dialog that thinks it is open.
      onCancel={(event) => {
        event.preventDefault()
        onClose()
      }}
      // A press on the backdrop lands on the dialog element itself, not on any
      // of its children. Presses inside the panel land on a child.
      onClick={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div className="flex items-center justify-between border-b border-[var(--border-subtle)] px-4 py-3">
        <span className="font-semibold">{t('common.appName')}</span>
        <button
          type="button"
          onClick={onClose}
          className="rounded-md p-2 text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
        >
          <span className="sr-only">{t('common.close')}</span>
          <svg viewBox="0 0 20 20" fill="currentColor" className="size-5" aria-hidden="true">
            <path d="M5.3 4.3a1 1 0 0 1 1.4 0L10 7.6l3.3-3.3a1 1 0 1 1 1.4 1.4L11.4 9l3.3 3.3a1 1 0 0 1-1.4 1.4L10 10.4l-3.3 3.3a1 1 0 0 1-1.4-1.4L8.6 9 5.3 5.7a1 1 0 0 1 0-1.4z" />
          </svg>
        </button>
      </div>

      <nav aria-label={t('nav.primary')} className="flex flex-1 flex-col gap-0.5 p-3">
        {/*
          The same groups the sidebar renders, through the same renderer. The
          drawer must never be the poorer navigation: that was the audit's
          finding, and one model with one renderer is what stops it recurring.
        */}
        <NavGroups groups={CUSTOMER_NAV_GROUPS} onNavigate={onClose} size="touch" />

        {isOperator ? (
          <>
            <hr className="my-2 border-[var(--border-subtle)]" />
            <NavGroups groups={OPERATOR_NAV_GROUPS} onNavigate={onClose} size="touch" />
          </>
        ) : null}
      </nav>

      <div className="flex items-center justify-between gap-2 border-t border-[var(--border-subtle)] px-4 py-3">
        <LocaleSwitcher />
        <Button variant="ghost" size="sm" onClick={onSignOut} loading={signingOut}>
          {t('common.signOut')}
        </Button>
      </div>
    </dialog>
  )
}
