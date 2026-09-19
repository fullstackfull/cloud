import { cn } from '@/lib/cn'

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

/**
 * The visual contract of a button, so that a link which acts like one looks
 * exactly like one.
 *
 * Extracted because three places need a control that navigates rather than
 * submits — the crash screen's way out, a deep link styled as a primary
 * action — and the alternatives are both bad: a `<button onClick={navigate}>`
 * is a link the customer cannot middle-click, open in a tab, or copy, and a
 * hand-copied class string is a second definition of "what a button looks
 * like" that drifts on the first restyle.
 */
const VARIANTS: Record<ButtonVariant, string> = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800',
  secondary:
    'border border-[var(--border-strong)] bg-[var(--surface-raised)] text-[var(--text-primary)] hover:bg-[var(--surface-sunken)]',
  ghost:
    'text-[var(--text-secondary)] hover:bg-[var(--surface-sunken)] hover:text-[var(--text-primary)]',
  // From the token system rather than a palette class, so the red on a
  // destructive button is the red on a danger zone's border and on a field
  // error. It used to be `bg-red-600`, which was a fourth red.
  danger: 'bg-[var(--danger-surface)] text-white hover:bg-[var(--danger-surface-strong)]',
}

const SIZES: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-sm',
  md: 'h-10 px-4 text-sm',
  lg: 'h-12 px-6 text-base',
}

export function buttonClasses(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
  className?: string,
): string {
  return cn(
    'inline-flex items-center justify-center gap-2 rounded-lg font-medium',
    'transition-colors disabled:cursor-not-allowed disabled:opacity-50',
    VARIANTS[variant],
    SIZES[size],
    className,
  )
}
