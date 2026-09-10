import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Button } from '@/components/Button'
import { LocaleSwitcher } from '@/components/LocaleSwitcher'

import { NavGroups } from './NavGroups'
import { CUSTOMER_NAV_GROUPS, OPERATOR_NAV_GROUPS } from './navigation'

/**
 * The desktop navigation: a column that stays put.
 *
 * It replaces a top bar that showed four destinations in the open and hid the
 * other seventeen behind a `More` menu — a menu that also swallowed the
 * operator's thirty-five links into one undifferentiated list. A column has
 * room to say what the platform is made of, so it does: something to buy, the
 * things you own, what they cost, help, and your account.
 *
 * Nothing here collapses. Collapsing is exactly what hid seventeen
 * destinations before, and the whole point of the group headings is that a
 * customer learns the shape of the platform once by reading them.
 */
export function Sidebar({
  isOperator,
  signingOut,
  onSignOut,
}: {
  isOperator: boolean
  signingOut: boolean
  onSignOut: () => void
}) {
  const { t } = useTranslation()

  return (
    <aside
      /*
       * Hidden below the desktop breakpoint, where the drawer is the
       * navigation. `lg` (1024px) rather than the `sm` the top bar used: a
       * column and a page side by side need the room, and a 768px tablet is
       * better served by the drawer than by a cramped pair.
       */
      className="hidden w-64 shrink-0 border-e border-[var(--border-subtle)] bg-[var(--surface-raised)] lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col"
    >
      <div className="border-b border-[var(--border-subtle)] px-4 py-4">
        <Link to="/" className="font-semibold text-[var(--text-primary)]">
          {t('common.appName')}
        </Link>
      </div>

      <nav
        aria-label={t('nav.primary')}
        className="flex flex-1 flex-col gap-0.5 overflow-y-auto p-3"
      >
        <NavGroups groups={CUSTOMER_NAV_GROUPS} />

        {isOperator ? (
          <>
            <hr className="my-2 border-[var(--border-subtle)]" />
            <NavGroups groups={OPERATOR_NAV_GROUPS} />
          </>
        ) : null}
      </nav>

      <div className="flex items-center justify-between gap-2 border-t border-[var(--border-subtle)] p-3">
        <LocaleSwitcher />

        <Button variant="ghost" size="sm" onClick={onSignOut} loading={signingOut}>
          {t('common.signOut')}
        </Button>
      </div>
    </aside>
  )
}
