import { useTranslation } from 'react-i18next'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useServiceEvents } from '@/lib/queries'
import type { ServiceEvent } from '@/lib/types'
import { safeLabel } from '@/lib/safeLabel'

/**
 * What has happened to this one resource.
 *
 * Sourced from the per-service events endpoint, which has existed since the
 * provisioning engine was built and had no caller in the portal at all. It is
 * already customer-safe at the API: the attempt log, the provider, the remote
 * job id and the internal error are withheld there, and the failure reason is
 * a code this portal translates.
 *
 * Per-resource only. The account-wide feed — who did what across the whole
 * account — is a different product with a different endpoint, and neither
 * exists yet: a page that stitched one together from six resources' histories
 * would be inventing it. A service with no history renders as an empty
 * section, not as an error and not as a placeholder row.
 */
export function ResourceActivity({ serviceId }: { serviceId: string | null }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { data, isPending, error } = useServiceEvents(serviceId)

  const columns: Array<Column<ServiceEvent>> = [
    {
      key: 'when',
      header: t('resource.activityWhen'),
      cell: (event) => formatDateTime(event.created_at, locale),
    },
    {
      key: 'what',
      header: t('resource.activityWhat'),
      cell: (event) => safeLabel('activity', event.kind),
    },
    {
      key: 'outcome',
      header: t('resource.activityOutcome'),
      cell: (event) => (
        <div className="flex flex-col gap-1">
          <StatusBadge status={event.state} />
          {event.failure_reason === null ? null : (
            <span className="text-xs text-[var(--text-secondary)]">
              {safeLabel('errors', event.failure_reason)}
            </span>
          )}
        </div>
      ),
    },
  ]

  if (serviceId === null) {
    return (
      <Card title={t('resource.activity')}>
        <p className="text-sm text-[var(--text-muted)]">{t('resource.noActivity')}</p>
      </Card>
    )
  }

  return (
    <Card title={t('resource.activity')} description={t('resource.activityBody')}>
      <LoadFailure error={error} />

      {isPending ? (
        <Loading />
      ) : (
        <DataTable
          caption={t('resource.activity')}
          columns={columns}
          rows={data?.data ?? []}
          rowKey={(event) => event.id}
          empty={t('resource.noActivityYet')}
        />
      )}
    </Card>
  )
}
