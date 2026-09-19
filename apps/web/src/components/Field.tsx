import { useId, type InputHTMLAttributes, type ReactNode } from 'react'

import { FieldShell } from '@/components/FieldShell'
import { describedBy } from '@/components/fieldIds'
import { cn } from '@/lib/cn'

interface FieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string
  error?: string | undefined
  hint?: ReactNode
  /** Keeps the label for assistive technology and out of the layout. */
  labelHidden?: boolean
}

/**
 * Input types whose content is always Latin script and must therefore render
 * left-to-right even inside an Arabic page. An email address or a URL laid out
 * right-to-left reads as nonsense, and a password field mirrors the caret in a
 * way that makes typing feel broken.
 */
const ALWAYS_LTR_TYPES = new Set(['email', 'password', 'url', 'tel'])

export function Field({ label, error, hint, labelHidden = false, className, ...props }: FieldProps) {
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
      <input
        id={id}
        dir={props.dir ?? (ALWAYS_LTR_TYPES.has(props.type ?? 'text') ? 'ltr' : undefined)}
        // Wired to the message rather than merely coloured red, so the error
        // is announced to a screen reader instead of being conveyed by colour
        // alone.
        aria-invalid={error !== undefined}
        aria-describedby={describedBy([
          error !== undefined ? errorId : null,
          hint !== undefined ? hintId : null,
        ])}
        className={cn(
          'h-10 rounded-lg border bg-[var(--surface-raised)] px-3 text-sm',
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
