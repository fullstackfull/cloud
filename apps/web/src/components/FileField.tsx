import { useId, type InputHTMLAttributes, type ReactNode, type Ref } from 'react'

import { FieldShell } from '@/components/FieldShell'
import { describedBy } from '@/components/fieldIds'
import { cn } from '@/lib/cn'

interface FileFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type'> {
  label: string
  error?: string | undefined
  hint?: ReactNode
  /** Keeps the label for assistive technology and out of the layout. */
  labelHidden?: boolean
  /**
   * Forwarded so a caller can clear the picker.
   *
   * A file input's value is not controllable from React, so a screen that
   * reads a file and then lets the customer edit what was read — the zone
   * import does exactly this — has to reset the element itself, or the picker
   * goes on naming a file whose contents are no longer what will be sent.
   */
  ref?: Ref<HTMLInputElement>
}

/**
 * A labelled file picker, on the same contract as every other field.
 *
 * The one control the design system did not cover, and the one whose native
 * appearance is least consistent: the button inside a file input is drawn by
 * the browser, differs on every platform, and is the only part of the control
 * most people click. Left unstyled it was the one element on the support form
 * that did not look like it belonged to the page.
 *
 * The button is therefore styled through `::file-selector-button` — the same
 * height, radius and token colours as a secondary Button — while the input
 * itself stays a real `<input type="file">`, because the alternative (a hidden
 * input behind a styled label) is how file pickers stop working with a
 * keyboard.
 */
export function FileField({
  label,
  error,
  hint,
  labelHidden = false,
  className,
  ref,
  ...props
}: FileFieldProps) {
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
        ref={ref}
        type="file"
        aria-invalid={error !== undefined}
        aria-describedby={describedBy([
          error !== undefined ? errorId : null,
          hint !== undefined ? hintId : null,
        ])}
        className={cn(
          'text-sm text-[var(--text-secondary)]',
          'file:me-3 file:h-9 file:cursor-pointer file:rounded-lg file:border-0',
          'file:bg-[var(--surface-sunken)] file:px-3 file:text-sm file:font-medium',
          'file:text-[var(--text-primary)]',
          className,
        )}
        {...props}
      />
    </FieldShell>
  )
}
