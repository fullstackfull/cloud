import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { isOffline, watchConnection } from '@/lib/connection'

/**
 * A standing notice while the browser has no network.
 *
 * The distinction it exists to draw is the one the audit's AC section is
 * about: a customer who has walked into a lift must not be told their reboot
 * failed. Nothing here retries anything and nothing here is dismissible —
 * dismissing it would not reconnect them, and a notice that can be dismissed
 * invites exactly the misreading that the problem has gone away.
 *
 * `aria-live="polite"` rather than `assertive`: losing a network is worth
 * saying at the next pause, not worth interrupting a sentence somebody is
 * part-way through reading.
 */
export function ConnectionNotice() {
  const { t } = useTranslation()
  const [offline, setOffline] = useState(isOffline)

  useEffect(() => watchConnection(setOffline), [])

  return (
    <div aria-live="polite">
      {offline ? (
        <div
          className={[
            'flex flex-wrap items-center gap-2 border-b px-4 py-2 sm:px-6',
            'border-[var(--warning-text)]/30 bg-[var(--surface-sunken)]',
            'text-sm text-[var(--text-primary)]',
          ].join(' ')}
        >
          <span className="font-medium">{t('connection.offlineTitle')}</span>
          <span className="text-[var(--text-secondary)]">{t('connection.offlineBody')}</span>
        </div>
      ) : null}
    </div>
  )
}
