import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'

const TONES = {
  supported: 'success',
  unsupported: 'neutral',
  unknown: 'neutral',
  blocked_licence: 'danger',
  blocked_credentials: 'danger',
  blocked_configuration: 'warning',
  blocked_network: 'danger',
} as const

/**
 * One observed capability. Its own vocabulary rather than the status badge's:
 * "unsupported" on a capability means the provider answered no, which is a
 * fact, not a fault — and not the "not sold" that the same word means on a
 * catalogue.
 */
export function CapabilityBadge({ capability, state }: { capability: string; state: string }) {
  const { t } = useTranslation()
  const tone = state in TONES ? TONES[state as keyof typeof TONES] : 'neutral'

  return (
    <span className="flex items-center gap-1 text-xs">
      <span className="technical">{capability}</span>
      <Badge tone={tone}>{t(`admin.providers.capabilityStates.${state}`)}</Badge>
    </span>
  )
}
