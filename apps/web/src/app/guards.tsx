import { Navigate, Outlet, useLocation } from 'react-router'

import { useCurrentUser } from '@/features/auth/useAuth'

function LoadingScreen() {
  return (
    <div
      className="flex min-h-dvh items-center justify-center text-sm text-[var(--text-secondary)]"
      role="status"
    >
      <span className="sr-only">Loading</span>
    </div>
  )
}

/**
 * Gate for the authenticated portal.
 *
 * The route the visitor asked for is carried through the redirect so that a
 * bookmarked deep link survives signing in, rather than dropping them on the
 * dashboard and making them navigate again.
 */
export function RequireAuth() {
  const { data: user, isPending } = useCurrentUser()
  const location = useLocation()

  if (isPending) return <LoadingScreen />

  if (user === null || user === undefined) {
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
