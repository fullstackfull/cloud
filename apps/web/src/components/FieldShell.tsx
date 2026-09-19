import type { ReactNode } from 'react'

interface FieldShellProps {
  /** What the control is called. Always rendered, never a placeholder. */
  label: string
  /** The control's own id, so the label points at it. */
  htmlFor: string
  hint?: ReactNode
  hintId: string
  error?: string | undefined
  errorId: string
  /**
   * Keeps the label in the accessibility tree and out of the layout.
   *
   * For a control inside a table cell, where the column header is already the
   * visible label and repeating it in every row would be noise — but where a
   * screen reader, which reads the cell without the header beside it, still
   * needs the control to say what it is.
   */
  labelHidden?: boolean
  children: ReactNode
}

/**
 * The label, hint and error around one form control.
 *
 * Every field in the portal used to write this itself: a `<label>`, a control,
 * a `<p>` for the hint, a `<p role="alert">` for the error, and the
 * `aria-describedby` wiring between them. Three components had it right, and
 * every hand-rolled `<select>` beside them had a `<span>` where the label
 * should have been — which is a control a screen reader reads as unlabelled.
 *
 * So it lives in one place. The rules it encodes are the ones that are easy to
 * get wrong and invisible when you do:
 *
 *  - The error is referenced by `aria-describedby` and marked `role="alert"`,
 *    so it is announced rather than merely coloured red. Colour alone is not a
 *    message.
 *  - The hint is referenced too, and comes *before* the error in the reading
 *    order, because "what is this field" is the question that comes first.
 *  - Requiredness is carried by the control's own `required` attribute, which
 *    assistive technology announces, and not by an asterisk in the label. An
 *    asterisk is a convention that has to be explained somewhere else on the
 *    page to mean anything, it reads aloud as "star", and putting it inside
 *    the `<label>` makes the field's name "Password *" — which is not what the
 *    field is called.
 *  - Danger comes from the design tokens rather than a raw palette class, so
 *    the red in a field error is the red in an alert and in a danger zone.
 */
export function FieldShell({
  label,
  htmlFor,
  hint,
  hintId,
  error,
  errorId,
  labelHidden = false,
  children,
}: FieldShellProps) {
  return (
    <div className="flex flex-col gap-1.5">
      <label
        htmlFor={htmlFor}
        className={
          labelHidden ? 'sr-only' : 'text-sm font-medium text-[var(--text-primary)]'
        }
      >
        {label}
      </label>

      {children}

      {hint === undefined || hint === null ? null : (
        <p id={hintId} className="text-xs text-[var(--text-muted)]">
          {hint}
        </p>
      )}

      {error === undefined ? null : (
        <p id={errorId} role="alert" className="text-xs text-[var(--danger-text)]">
          {error}
        </p>
      )}
    </div>
  )
}
