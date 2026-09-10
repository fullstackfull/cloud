import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/Button'

interface ConfirmDialogProps {
  open: boolean
  title: string
  /** What the customer is about to lose. Say it plainly. */
  body: ReactNode
  /**
   * When set, the confirm button stays disabled until the customer has typed
   * this string exactly. Used for operations that overwrite data, where a
   * misplaced click is not recoverable.
   */
  requiredPhrase?: string
  requiredPhraseLabel?: string
  /**
   * When set, a free-text box appears and the confirm button stays disabled
   * until something has been written in it. Used where the person acting is
   * asserting something the platform could not check for itself, and the
   * assertion is worthless without its basis.
   */
  evidenceLabel?: string
  evidenceHint?: string
  confirmLabel: string
  /**
   * What the way out is called. Defaults to "Cancel", which is wrong on
   * exactly one kind of dialog — the one that confirms a cancellation, where
   * two buttons reading "Cancel" are a coin toss. Those pass "Keep order".
   */
  cancelLabel?: string
  loading?: boolean
  /**
   * Whether confirming would do anything yet.
   *
   * A dialog whose body is still loading — a quote, a price, a list of what
   * will be lost — has a confirm button that must not be pressable, because
   * the handler behind it has nothing to act on and either fails or, worse,
   * silently returns. The invoice credit dialogue did the second: pressed
   * before its quote arrived, it did nothing at all, and a browser test that
   * clicked at the wrong moment waited ten seconds for an outcome that was
   * never requested. Defaults to true so a dialog with no asynchronous body
   * needs no change.
   */
  ready?: boolean
  error?: ReactNode
  onConfirm: (phrase: string, evidence: string) => void
  onCancel: () => void
}

/**
 * The dialog shown before something a customer cannot undo.
 *
 * Built on the native `<dialog>` element rather than a div with a high
 * z-index. The browser then owns the focus trap, the inertness of the page
 * behind, the Escape key and the accessibility tree — four things that are
 * each easy to get subtly wrong by hand, and whose failure modes are worst
 * exactly here: a customer who cannot tab to the cancel button, or whose
 * screen reader still reads the page behind, is a customer about to confirm
 * something they did not mean to.
 *
 * The typed phrase is deliberately compared exactly. The server compares it
 * the same way and is the one that decides; this copy exists so the customer
 * finds out before the request, not after.
 */
export function ConfirmDialog({
  open,
  title,
  body,
  requiredPhrase,
  requiredPhraseLabel,
  evidenceLabel,
  evidenceHint,
  confirmLabel,
  cancelLabel,
  loading = false,
  ready = true,
  error,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const { t } = useTranslation()
  const ref = useRef<HTMLDialogElement>(null)
  const [typed, setTyped] = useState('')
  const [evidence, setEvidence] = useState('')
  const inputId = useId()

  useEffect(() => {
    const dialog = ref.current

    if (dialog === null) return

    if (open && ! dialog.open) {
      // showModal, not show: the difference is the focus trap and the inert
      // background, which is the entire reason for using the element.
      dialog.showModal()
    } else if (! open && dialog.open) {
      dialog.close()
    }
  }, [open])

  useEffect(() => {
    // Cleared on every open, so a phrase typed for one machine can never be
    // sitting in the box when the dialog is reopened for a different one.
    if (open) {
      setTyped('')
      setEvidence('')
    }
  }, [open])

  const phraseSatisfied = requiredPhrase === undefined || typed === requiredPhrase
  // Three characters, the same floor the API enforces. "ok" is not a record of
  // what somebody looked at.
  const evidenceSatisfied = evidenceLabel === undefined || evidence.trim().length >= 3
  const satisfied = phraseSatisfied && evidenceSatisfied && ready

  return (
    <dialog
      ref={ref}
      aria-labelledby={`${inputId}-title`}
      onCancel={(event) => {
        event.preventDefault()
        onCancel()
      }}
      className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-raised)] p-0 text-[var(--text-primary)] backdrop:bg-black/50"
    >
      <form
        method="dialog"
        className="flex flex-col gap-4 p-6"
        onSubmit={(event) => {
          event.preventDefault()
          if (satisfied && ! loading) onConfirm(typed, evidence.trim())
        }}
      >
        <h2 id={`${inputId}-title`} className="text-lg font-semibold">
          {title}
        </h2>

        <div className="text-sm text-[var(--text-secondary)]">{body}</div>

        {requiredPhrase === undefined ? null : (
          <div className="flex flex-col gap-1.5">
            <label htmlFor={inputId} className="text-sm font-medium">
              {requiredPhraseLabel ?? t('confirm.typeToConfirm', { phrase: requiredPhrase })}
            </label>
            <input
              id={inputId}
              // Always left-to-right: the phrase is a hostname, and a hostname
              // laid out right-to-left inside an Arabic page cannot be checked
              // against the one on the screen above it.
              dir="ltr"
              autoComplete="off"
              spellCheck={false}
              value={typed}
              onChange={(event) => { setTyped(event.target.value); }}
              className="technical h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            />
          </div>
        )}

        {evidenceLabel === undefined ? null : (
          <div className="flex flex-col gap-1.5">
            <label htmlFor={`${inputId}-evidence`} className="text-sm font-medium">
              {evidenceLabel}
            </label>
            {evidenceHint === undefined ? null : (
              <p className="text-xs text-[var(--text-muted)]">{evidenceHint}</p>
            )}
            <textarea
              id={`${inputId}-evidence`}
              rows={3}
              value={evidence}
              onChange={(event) => { setEvidence(event.target.value); }}
              className="rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] p-3 text-sm"
            />
          </div>
        )}

        {error === undefined || error === null ? null : (
          <p role="alert" className="text-sm text-red-500">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onCancel} disabled={loading}>
            {cancelLabel ?? t('common.cancel')}
          </Button>
          {/*
            * type="button" with an explicit handler rather than a submit
            * button. Inside method="dialog" a submit is handled by the
            * browser's own dialog machinery, which means the action would
            * depend on an implementation detail that differs between
            * engines — and does not exist at all under jsdom, so a component
            * test could not press it. The form's onSubmit still runs, so
            * Enter in the text box confirms.
            */}
          <Button
            type="button"
            variant="danger"
            disabled={! satisfied}
            loading={loading}
            onClick={() => { if (satisfied && ! loading) onConfirm(typed, evidence.trim()); }}
          >
            {confirmLabel}
          </Button>
        </div>
      </form>
    </dialog>
  )
}
