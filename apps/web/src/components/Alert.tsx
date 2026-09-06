import type { ReactNode } from 'react'

import { cn } from '@/lib/cn'

type Tone = 'error' | 'warning' | 'info' | 'success'

const TONES: Record<Tone, string> = {
  error: 'border-red-500/30 bg-red-500/10 text-red-800 dark:text-red-200',
  warning: 'border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-200',
  info: 'border-blue-500/30 bg-blue-500/10 text-blue-800 dark:text-blue-200',
  success: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
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
