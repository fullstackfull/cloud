import { useId, type ReactNode, type SelectHTMLAttributes } from 'react'

import { FieldShell } from '@/components/FieldShell'
import { describedBy } from '@/components/fieldIds'
import { cn } from '@/lib/cn'

export interface SelectOption {
  value: string
  label: string
  disabled?: boolean
}

interface SelectFieldProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id'> {
  label: string
  /**
   * The choices, flat. Grouped choices pass `children` instead: a select whose
   * options come from two different sources — a customer's own zones and the
   * platform's defaults, say — needs `<optgroup>`, and flattening it would
   * lose the only thing that made the list readable.
   */
  options?: SelectOption[]
  error?: string | undefined
  hint?: ReactNode
  /** Keeps the label for assistive technology and out of the layout. */
  labelHidden?: boolean
}

/**
 * A labelled select, wired to its own error and hint.
 *
 * The same contract as Field, through the same shell: the label is bound to the
 * control with `htmlFor`, the error is referenced by `aria-describedby` and
 * announced rather than merely coloured red, and the invalid state is carried
 * by `aria-invalid` instead of by a border alone.
 */
export function SelectField({
  label,
  options,
  error,
  hint,
  labelHidden = false,
  className,
  children,
  ...props
}: SelectFieldProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  return (
    <FieldShell
      label={label}
      htmlFor={id}
      hint={hint}
      hintId={hintId}
      error={error}
      errorId={errorId}
      labelHidden={labelHidden}
    >
      <select
        id={id}
        aria-invalid={error !== undefined}
        aria-describedby={describedBy([
          error !== undefined ? errorId : null,
          hint !== undefined ? hintId : null,
        ])}
        className={cn(
          'h-10 rounded-lg border bg-[var(--surface-raised)] px-3 text-sm',
          'text-[var(--text-primary)] transition-colors',
          error !== undefined
            ? 'border-[var(--danger-border)] focus-visible:outline-[var(--danger-text)]'
            : 'border-[var(--border-subtle)]',
          className,
        )}
        {...props}
      >
        {children ??
          options?.map((option) => (
            <option key={option.value} value={option.value} disabled={option.disabled ?? false}>
              {option.label}
            </option>
          ))}
      </select>
    </FieldShell>
  )
}
