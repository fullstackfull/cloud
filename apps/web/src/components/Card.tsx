import { useId, type ReactNode } from 'react'

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
  /*
   * A titled card is a landmark, and a landmark needs a name.
   *
   * A `<section>` with no accessible name is not exposed as a region at all:
   * assistive technology sees a plain container and the heading floats free of
   * the thing it titles. Pointing the section at its own heading makes "Needs
   * your attention" a place a reader can jump to and skip — which on a
   * dashboard of six cards is the difference between navigating it and reading
   * all of it. Untitled cards stay anonymous, correctly: a wrapper with no
   * name is not a landmark.
   */
  const headingId = useId()

  return (
    <section
      {...(title === undefined ? {} : { 'aria-labelledby': headingId })}
      className={cn(
        'rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-raised)]',
        className,
      )}
    >
      {title !== undefined || actions !== undefined ? (
        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border-subtle)] p-4 sm:p-5">
          <div className="min-w-0">
            <h2 id={headingId} className="text-base font-semibold text-[var(--text-primary)]">
              {title}
            </h2>
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
