import { useId, type InputHTMLAttributes, type ReactNode } from 'react'

import { describedBy } from '@/components/fieldIds'
import { cn } from '@/lib/cn'

interface CheckboxFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type'> {
  /** The sentence beside the box. A checkbox's label is often a sentence. */
  label: ReactNode
  hint?: ReactNode
  error?: string | undefined
  /**
   * Keeps the label for assistive technology and out of the layout.
   *
   * For a box in a table cell, where the row already names what is being
   * ticked and repeating it beside every box would be noise — but where a
   * screen reader, reading the cell on its own, still needs the control to say
   * which row it belongs to.
   */
  labelHidden?: boolean
}

/**
 * A box a customer ticks to choose something.
 *
 * Deliberately **not** the same component as a switch, and the distinction is
 * behavioural rather than visual. A checkbox is part of a form: ticking it
 * changes what a later submit will do, and nothing happens until that submit.
 * A switch *is* the submit — flicking it changes a setting now. Styling both
 * the same way teaches a customer that neither can be trusted to have done
 * anything, which is how somebody ticks "auto-renew" and walks away.
 *
 * See {@link Switch} for the other one.
 *
 * The label wraps the control rather than pointing at it with `htmlFor`, so
 * that the whole sentence is a hit target — which on a phone is the difference
 * between a tick and three attempts.
 */
export function CheckboxField({
  label,
  hint,
  error,
  labelHidden = false,
  className,
  ...props
}: CheckboxFieldProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  return (
    <div className="flex flex-col gap-1.5">
      <label
        htmlFor={id}
        className={cn(
          'flex items-start gap-2 text-sm text-[var(--text-primary)]',
          // With no visible sentence there is nothing to sit beside, so the
          // box stops being nudged down onto a first line that is not there.
          labelHidden && 'items-center',
        )}
      >
        <input
          id={id}
          type="checkbox"
          aria-invalid={error !== undefined}
          aria-describedby={describedBy([
            error !== undefined ? errorId : null,
            hint !== undefined ? hintId : null,
          ])}
          className={cn(
            // Sized so the box itself is a reasonable target, and nudged down
            // to sit on the first line of a sentence that wraps.
            'size-4 shrink-0 rounded border-[var(--border-strong)]',
            labelHidden ? '' : 'mt-0.5',
            'accent-[var(--accent)]',
            className,
          )}
          {...props}
        />
        <span className={labelHidden ? 'sr-only' : undefined}>{label}</span>
      </label>

      {hint === undefined || hint === null ? null : (
        <p id={hintId} className="ms-6 text-xs text-[var(--text-muted)]">
          {hint}
        </p>
      )}

      {error === undefined ? null : (
        <p id={errorId} role="alert" className="ms-6 text-xs text-[var(--danger-text)]">
          {error}
        </p>
      )}
    </div>
  )
}
