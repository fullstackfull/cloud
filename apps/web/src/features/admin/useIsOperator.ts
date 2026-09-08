import { useCurrentUser } from '@/features/auth/useAuth'

/**
 * Whether to show the operator area at all.
 *
 * Presentation only. Every administrative endpoint checks its own permission
 * server-side, and a customer who guesses the URL gets a 403 whatever this
 * returns — which is the point: this hook decides what is worth drawing, not
 * what is allowed.
 */
const OPERATOR_PERMISSIONS = [
  'customer.view_any',
  'provisioning.view',
  'infrastructure.view',
  'invoice.view_any',
  'payment.view_any',
]

export function useIsOperator(): boolean {
  const { data: user } = useCurrentUser()

  return (user?.permissions ?? []).some((permission) => OPERATOR_PERMISSIONS.includes(permission))
}

export function useHasPermission(permission: string): boolean {
  const { data: user } = useCurrentUser()

  return (user?.permissions ?? []).includes(permission)
}
