import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useNavigate } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { LocaleSwitcher } from '@/components/LocaleSwitcher'
import { useCurrentUser, useLogout } from '@/features/auth/useAuth'
import { useIsOperator } from '@/features/admin/useIsOperator'
import { cn } from '@/lib/cn'

import { MobileNavigation } from './MobileNavigation'
import { CONTROL_CENTER_NAV, OPERATOR_NAV, PRIMARY_NAV, SECONDARY_NAV, type NavItem } from './navigation'

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
            {PRIMARY_NAV.map((item) => (
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
                    <hr className="my-1 border-[var(--border-subtle)]" />
                    <p className="px-3 py-1 text-xs font-medium tracking-wide text-[var(--text-muted)] uppercase">
                      {t('admin.controlCenter.section')}
                    </p>
                    {CONTROL_CENTER_NAV.map((item) => (
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
              aria-haspopup="dialog"
              onClick={() => { setMenuOpen((open) => !open); }}
            >
              <span className="sr-only">{t('nav.menu')}</span>
              <svg viewBox="0 0 20 20" fill="currentColor" className="size-5" aria-hidden="true">
                <path d="M3 5h14v2H3V5zm0 4h14v2H3V9zm0 4h14v2H3v-2z" />
              </svg>
            </button>
          </div>
        </div>

        <MobileNavigation
          open={menuOpen}
          isOperator={isOperator}
          signingOut={logout.isPending}
          onClose={() => { setMenuOpen(false); }}
          onSignOut={() => void signOut()}
        />
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
      end={item.end ?? item.to === '/'}
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
