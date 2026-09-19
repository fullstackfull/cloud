import { useId, type ReactNode } from 'react'

import { cn } from '@/lib/cn'

export interface RadioOption {
  value: string
  label: ReactNode
  hint?: ReactNode
  disabled?: boolean
}

interface RadioGroupProps {
  /** What the whole group is asking. Rendered as the fieldset's legend. */
  label: string
  /** Shared across the buttons, so the browser groups them. */
  name: string
  value: string
  options: RadioOption[]
  hint?: ReactNode
  error?: string | undefined
  /** Side by side, or stacked when each option carries an explanation. */
  orientation?: 'horizontal' | 'vertical'
  onChange: (value: string) => void
}

/**
 * A choice of one, as a real radio group.
 *
 * The last hand-rolled control family in the portal, and the one where the
 * hand-rolled version is most often subtly wrong: a group of radios is not a
 * set of fields, it is one field with several buttons, and that difference is
 * carried entirely by markup a reader cannot see. `<fieldset>` and `<legend>`
 * are what make a screen reader announce "Account type, individual, one of
 * two" rather than reading two unrelated controls; a shared `name` is what
 * makes the arrow keys move between them.
 *
 * Written as a component so those three things travel together, rather than
 * being remembered at each of the places that needs them.
 *
 * Radios rather than a select when the options are few and the choice changes
 * what the rest of the form asks for — the account type decides whether a
 * company name is wanted, and a choice with a consequence should be visible
 * without opening anything.
 */
export function RadioGroup({
  label,
  name,
  value,
  options,
  hint,
  error,
  orientation = 'horizontal',
  onChange,
}: RadioGroupProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  return (
    <fieldset
      className="flex flex-col gap-2"
      aria-invalid={error !== undefined}
      aria-describedby={
        [error !== undefined ? errorId : null, hint !== undefined ? hintId : null]
          .filter((part) => part !== null)
          .join(' ') || undefined
      }
    >
      <legend className="text-sm font-medium text-[var(--text-primary)]">{label}</legend>

      <div
        className={cn(
          'flex gap-4 text-sm',
          orientation === 'vertical' ? 'flex-col gap-2' : 'flex-wrap',
        )}
      >
        {options.map((option) => (
          <label
            key={option.value}
            className="flex items-start gap-2 text-[var(--text-secondary)]"
          >
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={value === option.value}
              disabled={option.disabled ?? false}
              onChange={() => { onChange(option.value); }}
              className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]"
            />
            <span>
              <span className="block text-[var(--text-primary)]">{option.label}</span>
              {option.hint === undefined ? null : (
                <span className="block text-xs text-[var(--text-muted)]">{option.hint}</span>
              )}
            </span>
          </label>
        ))}
      </div>

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
    </fieldset>
  )
}
