import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useNavigate } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { LocaleSwitcher } from '@/components/LocaleSwitcher'
import { useCurrentUser, useLogout } from '@/features/auth/useAuth'
import { useIsOperator } from '@/features/admin/useIsOperator'
import { cn } from '@/lib/cn'

interface NavItem {
  to: string
  labelKey: string
}

/**
 * Only what is actually reachable is listed.
 *
 * The navigation is not a roadmap: a link to a page that does not exist tells a
 * customer the platform can do something it cannot, and they will open a ticket
 * about it. Entries appear here as their endpoints are built.
 */
const NAV: NavItem[] = [
  { to: '/', labelKey: 'nav.dashboard' },
  { to: '/catalogue', labelKey: 'nav.catalogue' },
  { to: '/services', labelKey: 'nav.services' },
  { to: '/invoices', labelKey: 'nav.invoices' },
]

/**
 * Everything else, behind the account menu.
 *
 * The top bar holds what a customer opens most; a bar with fourteen items in it
 * is a bar nobody reads. These are still one click away and still in the
 * navigation landmark, so nothing is hidden from a screen reader.
 */
const SECONDARY_NAV: NavItem[] = [
  { to: '/orders', labelKey: 'nav.orders' },
  { to: '/subscriptions', labelKey: 'nav.subscriptions' },
  { to: '/wallet', labelKey: 'nav.wallet' },
  { to: '/vps', labelKey: 'nav.vps' },
  { to: '/backups', labelKey: 'nav.backups' },
  { to: '/notifications', labelKey: 'nav.notifications' },
  { to: '/dedicated', labelKey: 'nav.dedicated' },
  { to: '/hosting', labelKey: 'nav.hosting' },
  { to: '/ips', labelKey: 'nav.ips' },
  { to: '/api-tokens', labelKey: 'nav.apiKeys' },
  { to: '/settings/team', labelKey: 'nav.team' },
  { to: '/profile', labelKey: 'nav.profile' },
  { to: '/security', labelKey: 'nav.security' },
]

/**
 * Shown only to a login that holds at least one operator permission.
 *
 * Hiding it is a courtesy, not a control: the endpoints behind these screens
 * each check their own permission, and a customer who types the URL gets a 403
 * from every request the page makes.
 */
const OPERATOR_NAV: NavItem[] = [
  { to: '/admin/customers', labelKey: 'admin.nav.customers' },
  { to: '/admin/provisioning', labelKey: 'admin.nav.provisioning' },
  { to: '/admin/operations', labelKey: 'admin.nav.operations' },
  { to: '/admin/drift', labelKey: 'admin.nav.drift' },
  { to: '/admin/infrastructure', labelKey: 'admin.nav.infrastructure' },
  { to: '/admin/payments', labelKey: 'admin.nav.payments' },
]

export function AppLayout() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data: user } = useCurrentUser()
  const logout = useLogout()
  const isOperator = useIsOperator()
  const [menuOpen, setMenuOpen] = useState(false)

  async function signOut() {
    await logout.mutateAsync().catch(() => undefined)
    void navigate('/sign-in', { replace: true })
  }

  return (
    <div className="min-h-dvh bg-[var(--surface)]">
      <header className="sticky top-0 z-10 border-b border-[var(--border-subtle)] bg-[var(--surface-raised)]">
        <div className="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3 sm:px-6">
          <span className="font-semibold text-[var(--text-primary)]">{t('common.appName')}</span>

          <nav className="hidden flex-1 items-center gap-1 sm:flex" aria-label={t('nav.primary')}>
            {NAV.map((item) => (
              <NavItemLink key={item.to} item={item} />
            ))}

            <details className="relative ms-1">
              <summary className="cursor-pointer list-none rounded-md px-3 py-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)]">
                {t('nav.more')}
              </summary>
              <div className="absolute z-20 mt-1 flex w-56 flex-col gap-0.5 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-raised)] p-1 shadow-lg">
                {SECONDARY_NAV.map((item) => (
                  <NavItemLink key={item.to} item={item} />
                ))}

                {isOperator ? (
                  <>
                    <hr className="my-1 border-[var(--border-subtle)]" />
                    <p className="px-3 py-1 text-xs font-medium tracking-wide text-[var(--text-muted)] uppercase">
                      {t('admin.nav.section')}
                    </p>
                    {OPERATOR_NAV.map((item) => (
                      <NavItemLink key={item.to} item={item} />
                    ))}
                  </>
                ) : null}
              </div>
            </details>
          </nav>

          <div className="ms-auto flex items-center gap-2 sm:ms-0">
            <LocaleSwitcher className="hidden sm:flex" />

            <Button
              variant="ghost"
              size="sm"
              onClick={() => void signOut()}
              loading={logout.isPending}
            >
              {t('common.signOut')}
            </Button>

            <button
              type="button"
              className="rounded-md p-2 text-[var(--text-secondary)] sm:hidden"
              aria-expanded={menuOpen}
              aria-controls="portal-navigation"
              onClick={() => { setMenuOpen((open) => !open); }}
            >
              <span className="sr-only">{t('nav.dashboard')}</span>
              <svg viewBox="0 0 20 20" fill="currentColor" className="size-5" aria-hidden="true">
                <path d="M3 5h14v2H3V5zm0 4h14v2H3V9zm0 4h14v2H3v-2z" />
              </svg>
            </button>
          </div>
        </div>

        {menuOpen ? (
          <nav
            id="portal-navigation"
            className="flex flex-col gap-1 border-t border-[var(--border-subtle)] p-3 sm:hidden"
          >
            {NAV.map((item) => (
              <NavItemLink key={item.to} item={item} onNavigate={() => { setMenuOpen(false); }} />
            ))}
            <LocaleSwitcher className="mt-2" />
          </nav>
        ) : null}
      </header>

      <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
        {user?.email_verified === false ? (
          <div className="mb-6">
            <Alert tone="warning" title={t('account.verifyEmailTitle')}>
              {t('account.verifyEmailBody')}
            </Alert>
          </div>
        ) : null}

        <Outlet />
      </main>
    </div>
  )
}

function NavItemLink({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
  const { t } = useTranslation()

  return (
    <NavLink
      to={item.to}
      end={item.to === '/'}
      onClick={onNavigate}
      className={({ isActive }) =>
        cn(
          'rounded-md px-3 py-2 text-sm font-medium transition-colors',
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
