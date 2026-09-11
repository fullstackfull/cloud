import { useId, type ReactNode, type TextareaHTMLAttributes } from 'react'

import { FieldShell } from '@/components/FieldShell'
import { describedBy } from '@/components/fieldIds'
import { cn } from '@/lib/cn'

interface TextareaFieldProps extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> {
  label: string
  error?: string | undefined
  hint?: ReactNode
  /** Keeps the label for assistive technology and out of the layout. */
  labelHidden?: boolean
}

/**
 * A labelled multi-line field, on the same contract as Field.
 *
 * Four screens were hand-rolling this: a support request, a zone file paste, a
 * nameserver list and the evidence box in a confirmation. Each had its own
 * padding, its own row count, and — in two cases — a `<span>` above it instead
 * of a label.
 *
 * `dir` is left to the caller rather than inferred. A support request is
 * prose, and an Arabic customer writes it right to left; a pasted zone file is
 * a technical value and must stay left to right even on that same page. Only
 * the caller knows which of those it is holding.
 */
export function TextareaField({
  label,
  error,
  hint,
  labelHidden = false,
  className,
  rows = 4,
  ...props
}: TextareaFieldProps) {
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
      <textarea
        id={id}
        rows={rows}
        aria-invalid={error !== undefined}
        aria-describedby={describedBy([
          error !== undefined ? errorId : null,
          hint !== undefined ? hintId : null,
        ])}
        className={cn(
          'rounded-lg border bg-[var(--surface-raised)] p-3 text-sm',
          'text-[var(--text-primary)] placeholder:text-[var(--text-muted)]',
          'transition-colors',
          error !== undefined
            ? 'border-[var(--danger-border)] focus-visible:outline-[var(--danger-text)]'
            : 'border-[var(--border-subtle)]',
          className,
        )}
        {...props}
      />
    </FieldShell>
  )
}
