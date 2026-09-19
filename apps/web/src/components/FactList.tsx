import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

export interface Fact {
  label: string
  /**
   * The value, or null when the platform does not know it.
   *
   * Null renders as an explicit "not available" rather than as the word
   * "unknown", an empty cell, or a zero. The audit found fabricated values on
   * the VPS row — "NaN GiB" — and a blank tells a customer nothing about
   * whether the fact is missing or the screen is broken.
   */
  value: ReactNode | null
  /** Latin-only values — addresses, hostnames, serials — that must not mirror. */
  ltr?: boolean
  /** A short sentence under the value, for context the value cannot carry. */
  hint?: string
}

/**
 * The facts about one resource, as a definition list.
 *
 * A real `<dl>`: the label and the value are associated for a screen reader,
 * which a grid of `<div>`s does not do.
 */
export function FactList({ facts, columns = 3 }: { facts: readonly Fact[]; columns?: 2 | 3 }) {
  const { t } = useTranslation()

  return (
    <dl
      className={
        columns === 2
          ? 'grid gap-4 text-sm sm:grid-cols-2'
          : 'grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3'
      }
    >
      {facts.map((fact) => (
        <div key={fact.label} className="min-w-0">
          <dt className="text-[var(--text-muted)]">{fact.label}</dt>
          <dd
            className="mt-0.5 break-words text-[var(--text-primary)]"
            dir={fact.ltr === true && fact.value !== null ? 'ltr' : undefined}
          >
            {fact.value === null ? (
              <span className="text-[var(--text-muted)]">{t('resource.notAvailable')}</span>
            ) : (
              fact.value
            )}
          </dd>
          {fact.hint === undefined ? null : (
            <p className="mt-0.5 text-xs text-[var(--text-muted)]">{fact.hint}</p>
          )}
        </div>
      ))}
    </dl>
  )
}
