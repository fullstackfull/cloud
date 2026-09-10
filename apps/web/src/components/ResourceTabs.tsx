import { useTranslation } from 'react-i18next'
import { NavLink } from 'react-router'

import { cn } from '@/lib/cn'

export interface ResourceTab {
  /** Relative to the resource's own route: '' is the overview. */
  to: string
  labelKey: string
}

/**
 * The sections of a resource page, as routes.
 *
 * Links and not a `tablist`, deliberately. These sections are addresses: a
 * customer sends "the backups tab of web-01" to a colleague, refreshes it,
 * and bookmarks it. ARIA tabs are for panels within one document, cannot be
 * linked to, and would need the browser history reimplemented by hand. A
 * navigation of links gets the accessible current-page state for free, from
 * `aria-current`, which `NavLink` sets.
 *
 * The list scrolls sideways inside its own box on a phone rather than wrapping
 * into three rows or making the page scroll.
 */
export function ResourceTabs({ base, tabs }: { base: string; tabs: readonly ResourceTab[] }) {
  const { t } = useTranslation()

  return (
    <nav aria-label={t('resource.sections')} className="mb-6 -mx-1 overflow-x-auto">
      <ul className="flex min-w-max items-center gap-1 border-b border-[var(--border-subtle)] px-1">
        {tabs.map((tab) => (
          <li key={tab.to}>
            <NavLink
              to={tab.to === '' ? base : `${base}/${tab.to}`}
              end={tab.to === ''}
              className={({ isActive }) =>
                cn(
                  '-mb-px inline-block border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                  isActive
                    ? 'border-[var(--text-primary)] text-[var(--text-primary)]'
                    : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]',
                )
              }
            >
              {t(tab.labelKey)}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}
