import type { ReactNode } from 'react'

import { cn } from '@/lib/cn'

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

/** The same four tone families every other tinted surface reads. */
const TONES: Record<Tone, string> = {
  neutral: 'bg-[var(--surface-sunken)] text-[var(--text-secondary)] border-[var(--border-subtle)]',
  success:
    'bg-[var(--tone-success-surface)] text-[var(--tone-success-text)] border-[var(--tone-success-border)]',
  warning:
    'bg-[var(--tone-warning-surface)] text-[var(--tone-warning-text)] border-[var(--tone-warning-border)]',
  danger:
    'bg-[var(--tone-danger-surface)] text-[var(--tone-danger-text)] border-[var(--tone-danger-border)]',
  info: 'bg-[var(--tone-info-surface)] text-[var(--tone-info-text)] border-[var(--tone-info-border)]',
}

export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
        TONES[tone],
      )}
    >
      {children}
    </span>
  )
}
