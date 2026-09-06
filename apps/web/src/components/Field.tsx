import { useId, type InputHTMLAttributes, type ReactNode } from 'react'

import { cn } from '@/lib/cn'

interface FieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string
  error?: string | undefined
  hint?: ReactNode
}

/**
 * Input types whose content is always Latin script and must therefore render
 * left-to-right even inside an Arabic page. An email address or a URL laid out
 * right-to-left reads as nonsense, and a password field mirrors the caret in a
 * way that makes typing feel broken.
 */
const ALWAYS_LTR_TYPES = new Set(['email', 'password', 'url', 'tel'])

export function Field({ label, error, hint, className, ...props }: FieldProps) {
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

      <input
        id={id}
        dir={props.dir ?? (ALWAYS_LTR_TYPES.has(props.type ?? 'text') ? 'ltr' : undefined)}
        // Wired to the message rather than merely coloured red, so the error
        // is announced to a screen reader instead of being conveyed by colour
        // alone.
        aria-invalid={error !== undefined}
        aria-describedby={describedBy === '' ? undefined : describedBy}
        className={cn(
          'h-10 rounded-lg border bg-[var(--surface-raised)] px-3 text-sm',
          'text-[var(--text-primary)] placeholder:text-[var(--text-muted)]',
          'transition-colors',
          error !== undefined
            ? 'border-red-500 focus-visible:outline-red-500'
            : 'border-[var(--border-subtle)]',
          className,
        )}
        {...props}
      />

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
