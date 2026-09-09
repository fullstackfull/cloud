import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'

/**
 * Statuses arrive as the server's own vocabulary and are translated by key.
 *
 * Unrecognised values render verbatim rather than as a blank or a guess: a
 * status the portal has not been taught is still something the customer needs
 * to be able to read back to support.
 */
const TONES: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  running: 'success',
  paid: 'success',
  succeeded: 'success',
  completed: 'success',
  on: 'success',

  pending: 'info',
  provisioning: 'info',
  open: 'info',
  pending_payment: 'info',
  processing: 'info',

  delete_requested: 'warning',
  deleting: 'warning',

  suspended: 'warning',
  reactivating: 'info',
  maintenance: 'warning',
  past_due: 'warning',
  quarantined: 'warning',
  stopped: 'warning',
  off: 'warning',

  failed: 'danger',
  cancelled: 'danger',
  terminated: 'danger',
  void: 'danger',
  refused: 'danger',
  revoked: 'danger',
  // Credential references. Configured is not proven, so it is informational.
  missing: 'danger',
  configured: 'info',
  untested: 'info',
  valid: 'success',
  invalid: 'danger',
  rotation_due: 'warning',
  // Licences.
  expiring: 'warning',
  not_required: 'neutral',
  // Connections. Read-only is a success the operator must notice is partial.
  not_tested: 'neutral',
  testing: 'info',
  connected: 'success',
  connected_read_only: 'warning',
  auth_failed: 'danger',
  network_failed: 'danger',
  tls_failed: 'danger',
  licence_missing: 'danger',
  permission_insufficient: 'danger',
  provider_unavailable: 'danger',
  // Providers and readiness. Ready is not enabled; only enabled is green.
  ready: 'info',
  enabled: 'success',
  disabled: 'warning',
  blocked: 'danger',
  not_ready: 'neutral',
  ready_for_discovery: 'info',
  ready_for_configuration: 'info',
  ready_for_test: 'info',
  ready_for_production: 'success',
  // Machines.
  registered: 'neutral',
  discovered: 'info',
  profiled: 'info',
  managed: 'success',
  retired: 'neutral',
}

export function StatusBadge({ status }: { status: string }) {
  const { t } = useTranslation()

  return (
    <Badge tone={TONES[status] ?? 'neutral'}>
      {t(`status.${status}`, { defaultValue: status.replace(/_/g, ' ') })}
    </Badge>
  )
}
