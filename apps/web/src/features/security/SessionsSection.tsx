import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import {
  useRevokeOtherSessions,
  useRevokeSession,
  useSessions,
  type ActiveSession,
} from '@/features/account/useProfile'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

export function SessionsSection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data: sessions, isPending, error } = useSessions()
  const revoke = useRevokeSession()
  const revokeOthers = useRevokeOtherSessions()

  const displayed = describeError(error ?? revoke.error ?? revokeOthers.error)
  const others = (sessions ?? []).filter((session) => !session.is_current)

  const columns: Array<Column<ActiveSession>> = [
    {
      key: 'device',
      header: t('security.device'),
      ltr: true,
      cell: (session) => (
        <span className="block max-w-[22rem] truncate" title={session.user_agent ?? ''}>
          {session.user_agent ?? t('security.unknownDevice')}
        </span>
      ),
    },
    {
      key: 'ip',
      header: t('security.ipAddress'),
      ltr: true,
      cell: (session) => <span className="technical">{session.ip_address ?? '—'}</span>,
    },
    {
      key: 'seen',
      header: t('security.lastActive'),
      cell: (session) => formatDateTime(session.last_active_at, locale),
    },
    {
      key: 'actions',
      header: '',
      cell: (session) =>
        session.is_current ? (
          <Badge tone="info">{t('security.thisDevice')}</Badge>
        ) : (
          <Button
            variant="ghost"
            size="sm"
            loading={revoke.isPending && revoke.variables === session.id}
            onClick={() => { revoke.mutate(session.id); }}
          >
            {t('security.revoke')}
          </Button>
        ),
    },
  ]

  return (
    <Card
      title={t('security.sessionsTitle')}
      description={t('security.sessionsSubtitle')}
      actions={
        others.length > 0 ? (
          <Button
            variant="secondary"
            size="sm"
            loading={revokeOthers.isPending}
            onClick={() => { revokeOthers.mutate(); }}
          >
            {t('security.revokeOthers')}
          </Button>
        ) : undefined
      }
    >
      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      {isPending ? (
        <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
      ) : (
        <DataTable
          caption={t('security.sessionsTitle')}
          columns={columns}
          rows={sessions ?? []}
          rowKey={(session) => session.id}
          empty={t('security.noSessions')}
        />
      )}
    </Card>
  )
}
