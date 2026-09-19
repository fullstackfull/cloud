import { useTranslation } from 'react-i18next'
import { Navigate, Outlet, useLocation } from 'react-router'

import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Loading } from '@/components/Loading'
import { useCurrentUser } from '@/features/auth/useAuth'

function LoadingScreen() {
  return <Loading size="screen" />
}

/**
 * Gate for the authenticated portal.
 *
 * The route the visitor asked for is carried through the redirect so that a
 * bookmarked deep link survives signing in, rather than dropping them on the
 * dashboard and making them navigate again.
 *
 * ## Three answers, not two
 *
 * The profile probe can come back three ways and this gate used to collapse
 * them into two. `null` means the server said nobody is signed in — a 401,
 * which `useCurrentUser` maps deliberately because not being signed in is an
 * expected state on first load rather than a failure. `undefined` with an
 * error means the question could not be asked at all: the platform returned
 * 500, or the network died, and after two retries the portal still does not
 * know who this is.
 *
 * Both used to redirect to the sign-in page, which states something the portal
 * has no evidence for. A customer with a perfectly good session, on a platform
 * having a bad minute, was told to sign in again — and if they did, they were
 * signing in to a platform that was still down. It is the same defect as a
 * failed read rendering an empty table, on the one screen where the false
 * statement is about them rather than about their data.
 *
 * So a failed read says so and offers to ask again. Signing out is still
 * reachable from it, because a customer who believes they are signed in as
 * somebody else needs that door.
 */
export function RequireAuth() {
  const { t } = useTranslation()
  const { data: user, isPending, error, refetch } = useCurrentUser()
  const location = useLocation()

  if (isPending) return <LoadingScreen />

  // Could not ask. Not the same as being told no.
  if (error !== null && user === undefined) {
    return (
      <div className="mx-auto max-w-lg p-4 sm:p-6">
        <Card title={t('session.unknownTitle')}>
          <p className="text-sm text-[var(--text-secondary)]">{t('session.unknownBody')}</p>

          <div className="mt-4 flex flex-wrap gap-2">
            <Button onClick={() => { void refetch(); }}>{t('session.unknownRetry')}</Button>

            <Button variant="secondary" onClick={() => { window.location.assign('/sign-in'); }}>
              {t('session.unknownSignIn')}
            </Button>
          </div>
        </Card>
      </div>
    )
  }

  // Only `null` remains: the server said nobody is signed in. "Could not ask"
  // was answered above, and a successful read always carries a user.
  if (user === null) {
    return <Navigate to="/sign-in" replace state={{ from: location.pathname + location.search }} />
  }

  return <Outlet />
}

/** Gate for pages that only make sense while signed out. */
export function RequireGuest() {
  const { data: user, isPending } = useCurrentUser()

  if (isPending) return <LoadingScreen />

  if (user !== null && user !== undefined) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}

/**
 * Gate for the operator area.
 *
 * Presentation only, and worth being explicit about: every administrative
 * endpoint checks its own permission server-side, so this decides what is worth
 * drawing rather than what is allowed. A customer who guesses `/admin/customers`
 * is redirected here, and would have been refused by the API either way.
 */
export function RequireOperator() {
  const { data: user, isPending } = useCurrentUser()

  if (isPending) return <LoadingScreen />

  const isOperator = (user?.permissions ?? []).some((permission) =>
    OPERATOR_PERMISSIONS.includes(permission),
  )

  return isOperator ? <Outlet /> : <Navigate to="/" replace />
}

const OPERATOR_PERMISSIONS = [
  'customer.view_any',
  'provisioning.view',
  'infrastructure.view',
  'invoice.view_any',
  'payment.view_any',
]
