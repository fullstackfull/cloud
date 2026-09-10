import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Card } from '@/components/Card'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { useNotificationPreferences, useUpdateNotificationPreference } from '@/lib/queries'
import type { NotificationPreference } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Which optional messages this person wants.
 *
 * Categories the platform will not let anybody silence are shown, disabled,
 * with the reason — rather than omitted. A screen that simply left them out
 * would leave a customer wondering whether they had been switched off
 * silently, and the honest answer is that the platform will always tell them
 * their card was declined.
 *
 * The in-app inbox is never switchable, on any category. It is the account's
 * own record of what happened to it.
 */
export function NotificationPreferencesSection() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const { data, isPending, error: readError } = useNotificationPreferences()
  const update = useUpdateNotificationPreference()

  const failure = describeError(update.error)
  const rows = data?.data ?? []

  // Grouped by category so the two channels sit side by side, which is how
  // somebody reasons about it: "tell me about my services, but not by email".
  const categories = [...new Set(rows.map((row) => row.category))]

  return (
    <Card>
      <h2 className="mb-1 text-base font-semibold">{t('notifications.preferences.title')}</h2>
      <p className="mb-4 text-sm text-[var(--text-muted)]">
        {t('notifications.preferences.subtitle')}
      </p>

      <LoadFailure error={readError} />

      {failure === null ? null : (
        <div className="mb-3">
          <Alert tone="error" requestId={failure.requestId}>{failure.message}</Alert>
        </div>
      )}

      {isPending ? (
        <Loading className="py-6" />
      ) : (
        <ul className="divide-y divide-[var(--border-subtle)]">
          {categories.map((category) => (
            <li key={category} className="py-4">
              <p className="text-sm font-medium">
                {t(`notifications.category.${category}`, { defaultValue: category })}
              </p>
              <p className="mb-2 text-xs text-[var(--text-muted)]">
                {t(`notifications.categoryHint.${category}`, { defaultValue: '' })}
              </p>

              <div className="flex flex-wrap gap-4">
                {rows
                  .filter((row: NotificationPreference) => row.category === category)
                  .map((row) => (
                    <label key={row.channel} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={row.enabled}
                        disabled={! row.changeable || update.isPending}
                        onChange={(event) => {
                          update.mutate({
                            category: row.category,
                            channel: row.channel,
                            enabled: event.target.checked,
                          })
                        }}
                        className="size-4 rounded border-[var(--border-subtle)]"
                      />
                      <span className={row.changeable ? '' : 'text-[var(--text-muted)]'}>
                        {t(`notifications.channel.${row.channel}`, { defaultValue: row.channel })}
                      </span>
                      {row.changeable ? null : (
                        // Stated, not implied by a greyed box. "Always sent"
                        // is a policy the customer can read.
                        <span className="text-xs text-[var(--text-muted)]">
                          {t('notifications.preferences.always')}
                        </span>
                      )}
                    </label>
                  ))}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
