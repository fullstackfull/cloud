import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Card } from '@/components/Card'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { Switch } from '@/components/Switch'
import { useNotificationPreferences, useUpdateNotificationPreference } from '@/lib/queries'
import type { NotificationPreference } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { hasLabel, safeLabel } from '@/lib/safeLabel'

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
                {safeLabel('notifications.category', category)}
              </p>
              <p className="mb-2 text-xs text-[var(--text-muted)]">
                {hasLabel('notifications.categoryHint', category)
                  ? t(`notifications.categoryHint.${category}`)
                  : null}
              </p>

              {/*
                Switches, not checkboxes. Each of these saves the moment it is
                flicked: a checkbox looks like part of a form, so a customer
                turns email off, looks for the Save button, does not find one,
                and assumes it did not work. A switch announces as on/off and
                says what it is — a setting.
              */}
              <div className="flex flex-wrap gap-x-6 gap-y-2">
                {rows
                  .filter((row: NotificationPreference) => row.category === category)
                  .map((row) => (
                    <Switch
                      key={row.channel}
                      label={safeLabel('notifications.channel', row.channel)}
                      checked={row.enabled}
                      disabled={! row.changeable || update.isPending}
                      note={row.changeable ? undefined : t('notifications.preferences.always')}
                      onChange={(next) => {
                        update.mutate({
                          category: row.category,
                          channel: row.channel,
                          enabled: next,
                        })
                      }}
                    />
                  ))}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
