import { useId, type InputHTMLAttributes, type ReactNode } from 'react'

import { describedBy } from '@/components/fieldIds'
import { cn } from '@/lib/cn'

interface CheckboxFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type'> {
  /** The sentence beside the box. A checkbox's label is often a sentence. */
  label: ReactNode
  hint?: ReactNode
  error?: string | undefined
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
  className,
  ...props
}: CheckboxFieldProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="flex items-start gap-2 text-sm text-[var(--text-primary)]">
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
            'mt-0.5 size-4 shrink-0 rounded border-[var(--border-strong)]',
            'accent-[var(--accent)]',
            className,
          )}
          {...props}
        />
        <span>{label}</span>
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
