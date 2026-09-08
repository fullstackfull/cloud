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
}

export function StatusBadge({ status }: { status: string }) {
  const { t } = useTranslation()

  return (
    <Badge tone={TONES[status] ?? 'neutral'}>
      {t(`status.${status}`, { defaultValue: status.replace(/_/g, ' ') })}
    </Badge>
  )
}
