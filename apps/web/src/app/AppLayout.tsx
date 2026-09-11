import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Outlet, useNavigate } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { LocaleSwitcher } from '@/components/LocaleSwitcher'
import { useCurrentUser, useLogout } from '@/features/auth/useAuth'
import { useIsOperator } from '@/features/admin/useIsOperator'
import { applyTimeZone } from '@/lib/format'

import { ConnectionNotice } from './ConnectionNotice'
import { MobileNavigation } from './MobileNavigation'
import { RouteErrorBoundary } from './RouteErrorBoundary'
import { SessionExpiryNotice } from './SessionExpiryNotice'
import { MAIN_CONTENT_ID, SkipLink } from './SkipLink'
import { Sidebar } from './Sidebar'

/**
 * The signed-in shell: a navigation column beside the page.
 *
 * Two things decide the arrangement. Below 1024px there is no room for a
 * column, so the navigation is a drawer and a slim bar carries the way into
 * it. At 1024px and above the column is always there, and the bar is gone —
 * with the column holding the language switch and sign-out, a bar would only
 * repeat them, and two "Sign out" buttons on one screen is the kind of
 * duplicate the audit found in the top bar.
 */
export function AppLayout() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data: user } = useCurrentUser()
  const logout = useLogout()
  const isOperator = useIsOperator()
  const [menuOpen, setMenuOpen] = useState(false)

  /*
   * AS-17. Every date below this point is rendered in the customer's own time
   * zone rather than the browser's.
   *
   * Applied during render rather than in an effect, and that is the whole
   * reason it is a line of code here instead of a `useEffect`: an effect runs
   * after the first paint, so every date in the tree would be drawn once in
   * whatever zone the machine is set to and then corrected — which on a laptop
   * still set to Europe/London is a visible flicker between two different
   * times for the same reboot. The children of this layout render after this
   * function returns, so they see the right zone on their first pass.
   *
   * Idempotent, and cheap: it validates the name and assigns a string.
   */
  applyTimeZone(user?.timezone)

  async function signOut() {
    await logout.mutateAsync().catch(() => undefined)
    void navigate('/sign-in', { replace: true })
  }

  return (
    <div className="relative min-h-dvh bg-[var(--surface)] lg:flex">
      {/*
        First in the DOM so it is first in the tab order: a portal with
        twenty-one destinations in its sidebar puts twenty-one stops between
        the top of the page and the page.
      */}
      <SkipLink />

      <Sidebar
        isOperator={isOperator}
        signingOut={logout.isPending}
        onSignOut={() => void signOut()}
      />

      {/* `min-w-0`, or a wide table inside a flex child stretches the shell. */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 border-b border-[var(--border-subtle)] bg-[var(--surface-raised)] lg:hidden">
          <div className="flex items-center gap-4 px-4 py-3 sm:px-6">
            <Link to="/" className="font-semibold text-[var(--text-primary)]">
              {t('common.appName')}
            </Link>

            <div className="ms-auto flex items-center gap-2">
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
                className="rounded-md p-2 text-[var(--text-secondary)]"
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

        {/*
          Wider than the 72rem the audit measured 200px of dead space beside at
          1440. With the column taking 16rem, 80rem of content fills a 1440
          screen and a data-heavy table finally has room; wider screens keep a
          gutter, because a line of prose 1900px long is not a line anyone
          reads.
        */}
        <ConnectionNotice />

        <main
          id={MAIN_CONTENT_ID}
          // Focusable only as a skip-link target: -1 keeps it out of the tab
          // order while letting focus land here, so the next Tab continues
          // from the content rather than from the top of the document.
          tabIndex={-1}
          className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 sm:py-8 focus:outline-none"
        >
          {user?.email_verified === false ? (
            <div className="mb-6">
              <Alert tone="warning" title={t('account.verifyEmailTitle')}>
                <span className="flex flex-col items-start gap-2">
                  <span>{t('account.verifyEmailBody')}</span>

                  {/*
                    A way out of the banner, not just a statement of the
                    problem. Every screen that spends money refuses an
                    unverified account, and the verification page is where the
                    resend button lives — so the banner links to it rather than
                    leaving the customer to find it.
                  */}
                  <Link to="/verify-email" className="font-medium underline">
                    {t('account.verifyEmailAction')}
                  </Link>
                </span>
              </Alert>
            </div>
          ) : null}

          {/*
            Inside `<main>` so that a screen which throws takes the page with
            it and leaves the shell — the sidebar, the language switch and the
            way to sign out — standing.
          */}
          <RouteErrorBoundary>
            <Outlet />
          </RouteErrorBoundary>
        </main>
      </div>

      <SessionExpiryNotice />
    </div>
  )
}
