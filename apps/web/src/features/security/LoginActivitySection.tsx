import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import {
  SUCCESSFUL_LOGIN_OUTCOMES,
  useLoginActivity,
  type LoginActivityEntry,
} from '@/features/account/useProfile'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'

/**
 * Failed attempts are shown alongside successful ones on purpose: the whole
 * value of a sign-in history to a customer is recognising the attempt that was
 * not theirs, and a list of successes only tells them what they already know.
 */
function toneFor(outcome: string): 'success' | 'danger' | 'neutral' {
  if (SUCCESSFUL_LOGIN_OUTCOMES.includes(outcome)) return 'success'
  // Signing out and resetting a password are neither wins nor alarms; colouring
  // them red would train a customer to ignore the red rows that matter.
  if (outcome === 'logged_out' || outcome === 'password_reset') return 'neutral'
  return 'danger'
}

export function LoginActivitySection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { data: activity, isPending } = useLoginActivity()

  const columns: Array<Column<LoginActivityEntry>> = [
    {
      key: 'outcome',
      header: t('security.outcome'),
      cell: (entry) => (
        <Badge tone={toneFor(entry.outcome)}>
          {t(`security.outcomes.${entry.outcome}`, { defaultValue: entry.outcome })}
        </Badge>
      ),
    },
    {
      key: 'when',
      header: t('security.when'),
      cell: (entry) =>
        entry.occurred_at === null ? '—' : formatDateTime(entry.occurred_at, locale),
    },
    {
      key: 'ip',
      header: t('security.ipAddress'),
      ltr: true,
      cell: (entry) => (
        <span className="technical">
          {entry.ip_address ?? '—'}
          {entry.country !== null ? ` (${entry.country})` : ''}
        </span>
      ),
    },
    {
      key: 'device',
      header: t('security.device'),
      ltr: true,
      cell: (entry) => (
        <span className="block max-w-[22rem] truncate" title={entry.user_agent ?? ''}>
          {entry.user_agent ?? t('security.unknownDevice')}
        </span>
      ),
    },
  ]

  return (
    <Card title={t('security.activityTitle')} description={t('security.activitySubtitle')}>
      {isPending ? (
        <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
      ) : (
        <DataTable
          caption={t('security.activityTitle')}
          columns={columns}
          rows={activity ?? []}
          rowKey={(entry) => entry.id}
          empty={t('security.noActivity')}
        />
      )}
    </Card>
  )
}
