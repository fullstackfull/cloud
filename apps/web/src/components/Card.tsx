import type { ReactNode } from 'react'

import { cn } from '@/lib/cn'

interface CardProps {
  title?: ReactNode
  description?: ReactNode
  actions?: ReactNode
  footer?: ReactNode
  className?: string
  children: ReactNode
}

export function Card({ title, description, actions, footer, className, children }: CardProps) {
  return (
    <section
      className={cn(
        'rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-raised)]',
        className,
      )}
    >
      {title !== undefined || actions !== undefined ? (
        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border-subtle)] p-4 sm:p-5">
          <div className="min-w-0">
            <h2 className="text-base font-semibold text-[var(--text-primary)]">{title}</h2>
            {description !== undefined ? (
              <p className="mt-1 text-sm text-[var(--text-secondary)]">{description}</p>
            ) : null}
          </div>
          {actions !== undefined ? <div className="shrink-0">{actions}</div> : null}
        </header>
      ) : null}

      <div className="p-4 sm:p-5">{children}</div>

      {footer !== undefined ? (
        <footer className="border-t border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-4 sm:px-5">
          {footer}
        </footer>
      ) : null}
    </section>
  )
}
