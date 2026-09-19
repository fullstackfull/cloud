import { useTranslation } from 'react-i18next'

import type { Domain } from '@/lib/types'

/**
 * Why the controls on this section are off.
 *
 * A name the platform cannot currently act on — a registration the registrar
 * never confirmed, a transfer in progress, a name in redemption — keeps its
 * controls visible and disabled rather than having them disappear, because a
 * missing button leaves a customer wondering whether the platform can do the
 * thing at all. What a disabled button needs is the sentence beside it, and
 * this is that sentence.
 */
export function NotManageableNote({ domain }: { domain: Domain }) {
  const { t } = useTranslation()

  if (domain.is_manageable) return null

  return <p className="text-sm text-[var(--warning-text)]">{t('domains.notManageable')}</p>
}
