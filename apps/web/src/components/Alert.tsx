import type { ReactNode } from 'react'

import { cn } from '@/lib/cn'

type Tone = 'error' | 'warning' | 'info' | 'success'

/**
 * One line per meaning, all three slots from the same tone family.
 *
 * These were raw palette classes until Wave 5 — which meant the red in an
 * alert was not the red in the badge beside it, neither was the red a field
 * error uses, and none of them moved when the theme did.
 */
const TONES: Record<Tone, string> = {
  error:
    'border-[var(--tone-danger-border)] bg-[var(--tone-danger-surface)] text-[var(--tone-danger-text)]',
  warning:
    'border-[var(--tone-warning-border)] bg-[var(--tone-warning-surface)] text-[var(--tone-warning-text)]',
  info: 'border-[var(--tone-info-border)] bg-[var(--tone-info-surface)] text-[var(--tone-info-text)]',
  success:
    'border-[var(--tone-success-border)] bg-[var(--tone-success-surface)] text-[var(--tone-success-text)]',
}

interface AlertProps {
  tone?: Tone
  title?: string
  children: ReactNode
  /** Correlation id of the failing request, so support can find it in the logs. */
  requestId?: string | undefined
}

export function Alert({ tone = 'info', title, children, requestId }: AlertProps) {
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn('rounded-lg border p-3 text-sm', TONES[tone])}
    >
      {title !== undefined ? <p className="mb-1 font-medium">{title}</p> : null}
      <div>{children}</div>
      {requestId !== undefined ? (
        <p className="technical mt-2 text-xs opacity-70">{requestId}</p>
      ) : null}
    </div>
  )
}
