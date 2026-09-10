import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useDedicatedServers } from '@/lib/queries'
import type { DedicatedServer } from '@/lib/types'

import { DedicatedPowerActions } from './dedicated/DedicatedPowerActions'

/**
 * The dedicated machines this account has: an index, since Wave 3.
 *
 * The row identifies the machine and links to it. The rebuild — the one
 * irreversible thing that can be done to a dedicated server — moved to the
 * machine's own danger zone, where a destructive control belongs, and the one
 * power control a customer reaches for from a list stayed. Both are the same
 * components the machine's page uses, so there is one mutation path per act.
 */
export function DedicatedPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useDedicatedServers(page)

  const columns: Array<Column<DedicatedServer>> = [
    {
      key: 'machine',
      header: t('dedicated.machine'),
      ltr: true,
      cell: (server) => (
        <div>
          <Link
            to={`/dedicated/${server.id}`}
            className="technical font-medium text-[var(--text-primary)] hover:underline"
          >
            {server.serial}
          </Link>
          <p className="text-xs text-[var(--text-muted)]" dir="ltr">
            {server.manufacturer} {server.model}
          </p>
        </div>
      ),
    },
    {
      key: 'status',
      header: t('dedicated.status'),
      cell: (server) => <StatusBadge status={server.status} />,
    },
    {
      key: 'power',
      header: t('dedicated.power'),
      cell: (server) => <StatusBadge status={server.power_state} />,
    },
    {
      key: 'since',
      header: t('dedicated.since'),
      cell: (server) =>
        server.activated_at == null ? '—' : formatDate(server.activated_at, locale),
    },
    {
      key: 'actions',
      header: '',
      cell: (server) => (
        <div className="flex flex-wrap items-center justify-end gap-2">
          <DedicatedPowerActions server={server} only={['cycle']} />
          <Link to={`/dedicated/${server.id}`} className="text-sm underline">
            {t('resource.open')}
          </Link>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.dedicated')} description={t('dedicated.subtitle')} />

      <LoadFailure error={readError} />

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.dedicated')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(server) => server.id}
              empty={t('dedicated.empty')}
            />
            <Paginator
              page={data?.meta.page ?? 1}
              lastPage={data?.meta.last_page ?? 1}
              total={data?.meta.total ?? 0}
              onChange={setPage}
            />
          </>
        )}
      </Card>
    </>
  )
}
