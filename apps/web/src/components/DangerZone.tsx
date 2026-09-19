import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * The actions that destroy something, kept apart from the ones that do not.
 *
 * Separation is the whole point: a reinstall that erases a disk should not sit
 * next to "Reboot" where a thumb can reach it by accident. What it is not is a
 * decoration — an ordinary action in a red box teaches a customer to ignore
 * red boxes, so only genuinely destructive or irreversible operations belong
 * here. Confirmation policy is unchanged: the typed-identity dialogs these
 * actions open are the same ones Wave 0 built.
 */
export function DangerZone({ children }: { children: ReactNode }) {
  const { t } = useTranslation()

  return (
    <section className="rounded-xl border border-[var(--danger-border)] bg-[var(--surface-raised)]">
      <header className="border-b border-[var(--danger-border)] p-4 sm:p-5">
        <h2 className="text-base font-semibold text-[var(--danger-text)]">{t('resource.dangerZone')}</h2>
        <p className="mt-1 text-sm text-[var(--text-secondary)]">{t('resource.dangerZoneBody')}</p>
      </header>

      <div className="divide-y divide-[var(--border-subtle)]">{children}</div>
    </section>
  )
}

/**
 * One destructive action: what it does, what it costs, and the control.
 */
export function DangerAction({
  title,
  body,
  action,
}: {
  title: string
  body: ReactNode
  action: ReactNode
}) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-5">
      <div className="min-w-0 max-w-2xl">
        <h3 className="text-sm font-medium text-[var(--text-primary)]">{title}</h3>
        <p className="mt-1 text-sm text-[var(--text-secondary)]">{body}</p>
      </div>

      <div className="shrink-0">{action}</div>
    </div>
  )
}
