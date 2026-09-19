import { useId, type ReactNode } from 'react'

import { cn } from '@/lib/cn'

interface SwitchProps {
  /** What the setting is called. */
  label: ReactNode
  checked: boolean
  onChange: (next: boolean) => void
  disabled?: boolean
  /** A sentence under the switch: what being on means, or why it is fixed. */
  hint?: ReactNode
  /** Shown beside the label when the setting cannot be changed. */
  note?: ReactNode
  className?: string
}

/**
 * A setting that changes the moment it is flicked.
 *
 * The counterpart to {@link CheckboxField}, and the difference is behaviour
 * rather than decoration. A checkbox is part of a form: ticking it changes
 * what a later submit will do. A switch *is* the submit. Notification
 * preferences were checkboxes that saved on change — a control that looks like
 * it is waiting for a submit button and has in fact already committed — which
 * is how somebody turns email off, looks for the Save button, does not find
 * one, and assumes it did not work.
 *
 * Built as a `<button role="switch">` rather than a styled checkbox. The role
 * is the one assistive technology announces as "on"/"off" instead of
 * "checked"/"unchecked", which is the right vocabulary for a setting, and a
 * button gives the space and enter keys for free.
 *
 * The thumb's movement is a transition, and `motion-reduce` removes it: a
 * customer who has asked their system for less animation has asked for less
 * animation here too.
 */
export function Switch({
  label,
  checked,
  onChange,
  disabled = false,
  hint,
  note,
  className,
}: SwitchProps) {
  const id = useId()
  const hintId = `${id}-hint`

  return (
    <div className={cn('flex flex-col gap-1', className)}>
      <div className="flex items-center gap-2">
        <button
          type="button"
          role="switch"
          aria-checked={checked}
          aria-labelledby={id}
          aria-describedby={hint === undefined || hint === null ? undefined : hintId}
          disabled={disabled}
          onClick={() => { onChange(! checked); }}
          className={cn(
            // A 44-pixel-tall hit area around a 20-pixel track: the track is
            // what a switch looks like, the padding is what a thumb finds.
            'inline-flex h-11 shrink-0 items-center rounded-full px-0.5 py-0',
            'disabled:cursor-not-allowed disabled:opacity-60',
          )}
        >
          <span
            className={cn(
              'flex h-5 w-9 items-center rounded-full border transition-colors motion-reduce:transition-none',
              checked
                ? 'border-[var(--accent)] bg-[var(--accent)]'
                : 'border-[var(--border-strong)] bg-[var(--surface-sunken)]',
            )}
          >
            <span
              aria-hidden="true"
              className={cn(
                'size-4 rounded-full bg-white shadow-sm transition-transform motion-reduce:transition-none',
                // Moved with a logical margin rather than translate, so the
                // thumb sits on the correct side of the track in Arabic.
                checked ? 'ms-4' : 'ms-0.5',
              )}
            />
          </span>
        </button>

        <span id={id} className="text-sm text-[var(--text-primary)]">
          {label}
        </span>

        {note === undefined || note === null ? null : (
          <span className="text-xs text-[var(--text-muted)]">{note}</span>
        )}
      </div>

      {hint === undefined || hint === null ? null : (
        <p id={hintId} className="text-xs text-[var(--text-muted)]">
          {hint}
        </p>
      )}
    </div>
  )
}
