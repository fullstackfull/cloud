import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'
import { toneFor } from '@/lib/statusVocabulary'

/**
 * Statuses arrive as the server's own vocabulary and are translated by key,
 * toned by the shared vocabulary in `lib/statusVocabulary`.
 *
 * Unrecognised values render verbatim rather than as a blank or a guess: a
 * status the portal has not been taught is still something the customer needs
 * to be able to read back to support. The parity test on the vocabulary makes
 * that path unreachable for every status the API is known to publish.
 */
export function StatusBadge({ status }: { status: string }) {
  const { t } = useTranslation()

  return (
    <Badge tone={toneFor(status)}>
      {t(`status.${status}`, { defaultValue: status.replace(/_/g, ' ') })}
    </Badge>
  )
}
