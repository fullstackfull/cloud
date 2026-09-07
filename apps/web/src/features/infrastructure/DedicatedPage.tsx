import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useDedicatedPower, useDedicatedServers } from '@/lib/queries'
import type { DedicatedServer } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

const POWER_ACTIONS = ['on', 'off', 'cycle'] as const

export function DedicatedPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const { data, isPending } = useDedicatedServers(page)
  const power = useDedicatedPower()

  const displayed = describeError(power.error)

  const columns: Array<Column<DedicatedServer>> = [
    {
      key: 'machine',
      header: t('dedicated.machine'),
      ltr: true,
      cell: (server) => (
        <div>
          <p className="font-medium text-[var(--text-primary)]">
            {server.manufacturer} {server.model}
          </p>
          <p className="technical text-xs text-[var(--text-muted)]">{server.serial}</p>
        </div>
      ),
    },
    { key: 'status', header: t('dedicated.status'), cell: (server) => <StatusBadge status={server.status} /> },
    { key: 'power', header: t('dedicated.power'), cell: (server) => <StatusBadge status={server.power_state} /> },
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
        <div className="flex flex-wrap gap-1">
          {POWER_ACTIONS.map((action) => (
            <Button
              key={action}
              size="sm"
              variant={action === 'off' ? 'danger' : 'ghost'}
              loading={
                power.isPending && power.variables.id === server.id && power.variables.action === action
              }
              onClick={() => { power.mutate({ id: server.id, action, idempotency_key: crypto.randomUUID() }); }
              }
            >
              {t(`dedicated.actions.${action}`)}
            </Button>
          ))}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.dedicated')} description={t('dedicated.subtitle')} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
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
