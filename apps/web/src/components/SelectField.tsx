import { useId, type ReactNode, type SelectHTMLAttributes } from 'react'

import { cn } from '@/lib/cn'

export interface SelectOption {
  value: string
  label: string
}

interface SelectFieldProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id' | 'children'> {
  label: string
  options: SelectOption[]
  error?: string | undefined
  hint?: ReactNode
}

/**
 * A labelled select, wired to its own error and hint.
 *
 * The same contract as Field, for the same reason: the label is bound to the
 * control with `htmlFor`, the error is referenced by `aria-describedby` and
 * announced rather than merely coloured red, and the invalid state is carried
 * by `aria-invalid` instead of by a border alone. Two screens had been
 * hand-rolling a bare `<select>` with a `<span>` beside it, which is a control
 * a screen reader reads as unlabelled.
 */
export function SelectField({ label, options, error, hint, className, ...props }: SelectFieldProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  const describedBy = [error !== undefined ? errorId : null, hint !== undefined ? hintId : null]
    .filter((value): value is string => value !== null)
    .join(' ')

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-[var(--text-primary)]">
        {label}
      </label>

      <select
        id={id}
        aria-invalid={error !== undefined}
        aria-describedby={describedBy === '' ? undefined : describedBy}
        className={cn(
          'h-10 rounded-lg border bg-[var(--surface-raised)] px-3 text-sm',
          'text-[var(--text-primary)] transition-colors',
          error !== undefined
            ? 'border-red-500 focus-visible:outline-red-500'
            : 'border-[var(--border-subtle)]',
          className,
        )}
        {...props}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>

      {hint !== undefined ? (
        <p id={hintId} className="text-xs text-[var(--text-muted)]">
          {hint}
        </p>
      ) : null}

      {error !== undefined ? (
        <p id={errorId} role="alert" className="text-xs text-red-600 dark:text-red-400">
          {error}
        </p>
      ) : null}
    </div>
  )
}
