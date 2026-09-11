import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import {
  useRevokeOtherSessions,
  useRevokeSession,
  useSessions,
  type ActiveSession,
} from '@/features/account/useProfile'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { useDeviceName } from '@/lib/useDeviceName'

export function SessionsSection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()
  const describe = useDeviceName()

  const { data: sessions, isPending, error } = useSessions()
  const revoke = useRevokeSession()
  const revokeOthers = useRevokeOtherSessions()

  const displayed = describeError(revoke.error ?? revokeOthers.error)
  const others = (sessions ?? []).filter((session) => !session.is_current)

  /*
   * What is about to be signed out: one named device, or every other one.
   * A plain confirmation — the person on the other end has to sign in again,
   * which is disruptive and entirely recoverable.
   */
  const [revoking, setRevoking] = useState<ActiveSession | null>(null)
  const [revokingOthers, setRevokingOthers] = useState(false)

  const columns: Array<Column<ActiveSession>> = [
    {
      key: 'device',
      header: t('security.device'),
      /*
       * A browser and an operating system, from a bounded parser — never a
       * device model, never a place, and "Unknown browser" where the string
       * does not say. The column used to print the raw header truncated at
       * 22rem, which is the same sixty characters on every desktop session a
       * customer has, so the one control that matters here could not be aimed.
       *
       * The header itself stays available in `title`: it is the value somebody
       * quotes to support, and hiding it entirely would trade one problem for
       * another.
       */
      cell: (session) => (
        <span title={session.user_agent ?? ''}>{describe(session.user_agent)}</span>
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
            onClick={() => { setRevoking(session); }}
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
            onClick={() => { setRevokingOthers(true); }}
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

      {/* A failed read is reported, never rendered as "no sessions". */}
      <LoadFailure error={error} />
      {isPending ? (
        <Loading />
      ) : error !== null ? null : (
        <DataTable
          caption={t('security.sessionsTitle')}
          columns={columns}
          rows={sessions}
          rowKey={(session) => session.id}
          empty={t('security.noSessions')}
        />
      )}

      <ConfirmDialog
        open={revoking !== null}
        title={t('security.revokeSessionDialog.title')}
        body={
          <p>
            {t('security.revokeSessionDialog.body', {
              device: describe(revoking?.user_agent ?? null),
              ip: revoking?.ip_address ?? '—',
            })}
          </p>
        }
        confirmLabel={t('security.revokeSessionDialog.confirmLabel')}
        loading={revoke.isPending}
        onConfirm={() => {
          if (revoking === null) return

          revoke.mutate(revoking.id, { onSettled: () => { setRevoking(null); } })
        }}
        onCancel={() => { setRevoking(null); }}
      />

      <ConfirmDialog
        open={revokingOthers}
        title={t('security.revokeOthersDialog.title')}
        body={<p>{t('security.revokeOthersDialog.body')}</p>}
        confirmLabel={t('security.revokeOthersDialog.confirmLabel')}
        loading={revokeOthers.isPending}
        onConfirm={() => { revokeOthers.mutate(undefined, { onSettled: () => { setRevokingOthers(false); } }); }}
        onCancel={() => { setRevokingOthers(false); }}
      />
    </Card>
  )
}
