import { useTranslation } from 'react-i18next'
import { NavLink } from 'react-router'

import { cn } from '@/lib/cn'

import type { NavGroup, NavItem } from './navigation'

/**
 * One renderer for the navigation list, used by the sidebar and by the phone
 * drawer.
 *
 * The two shells differ — one is a persistent column, the other a modal
 * dialog — but the list inside them is the same list, laid out the same way.
 * Before Wave 3 each shell had its own near-identical copy of the link
 * component, which is how they drifted apart in the first place.
 */
export function NavGroups({
  groups,
  onNavigate,
  size = 'compact',
}: {
  groups: readonly NavGroup[]
  onNavigate?: () => void
  /** A phone drawer wants a bigger touch target than a desktop column. */
  size?: 'compact' | 'touch'
}) {
  const { t } = useTranslation()

  return (
    <>
      {groups.map((group) => (
        <div key={group.id} className="flex flex-col gap-0.5">
          {group.labelKey === null ? null : (
            <p className="px-3 pt-3 pb-1 text-xs font-medium tracking-wide text-[var(--text-muted)] uppercase">
              {t(group.labelKey)}
            </p>
          )}

          {group.items.map((item) => (
            <NavItemLink
              key={item.to}
              item={item}
              size={size}
              /*
               * Spread rather than passed as undefined: under
               * exactOptionalPropertyTypes an optional prop must be absent,
               * not present-and-undefined.
               */
              {...(onNavigate === undefined ? {} : { onNavigate })}
            />
          ))}
        </div>
      ))}
    </>
  )
}

export function NavItemLink({
  item,
  onNavigate,
  size = 'compact',
}: {
  item: NavItem
  onNavigate?: () => void
  size?: 'compact' | 'touch'
}) {
  const { t } = useTranslation()

  return (
    <NavLink
      to={item.to}
      /*
       * Exact matching only where a section's index would otherwise light up
       * for every child. `/vps` deliberately stays lit while a customer is on
       * `/vps/{id}`: they are inside Cloud VPS, and a navigation that forgot
       * where they were as soon as they opened a machine would be lying.
       */
      end={item.end ?? item.to === '/'}
      onClick={onNavigate}
      className={({ isActive }) =>
        cn(
          'rounded-md text-sm font-medium transition-colors',
          size === 'touch' ? 'px-3 py-2.5' : 'px-3 py-2',
          isActive
            ? 'bg-[var(--surface-sunken)] text-[var(--text-primary)]'
            : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]',
        )
      }
    >
      {t(item.labelKey)}
    </NavLink>
  )
}
