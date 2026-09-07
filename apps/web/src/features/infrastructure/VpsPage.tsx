import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useVirtualMachines, useVpsPower } from '@/lib/queries'
import type { VirtualMachine } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * `stop` and `shutdown` are shown as two different buttons because they are two
 * different things: one pulls the plug, the other asks the guest to close its
 * files first. Collapsing them into one control is how a customer loses a
 * database.
 */
const POWER_ACTIONS = ['start', 'shutdown', 'stop', 'reboot'] as const

export function VpsPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useVirtualMachines(page)
  const power = useVpsPower()

  const displayed = describeError(power.error)

  const columns: Array<Column<VirtualMachine>> = [
    {
      key: 'hostname',
      header: t('vps.hostname'),
      ltr: true,
      cell: (vm) => <span className="technical font-medium">{vm.hostname}</span>,
    },
    {
      key: 'spec',
      header: t('vps.spec'),
      ltr: true,
      cell: (vm) => (
        <span className="technical text-xs">
          {vm.vcpu} vCPU · {Math.round(vm.memory_mib / 1024)} GiB · {vm.disk_gib} GiB
        </span>
      ),
    },
    {
      key: 'address',
      header: t('vps.address'),
      ltr: true,
      cell: (vm) => (
        <span className="technical">
          {vm.addresses.find((address) => address.is_primary)?.address ?? '—'}
        </span>
      ),
    },
    { key: 'power', header: t('vps.power'), cell: (vm) => <StatusBadge status={vm.power_state} /> },
    {
      key: 'actions',
      header: '',
      cell: (vm) => (
        <div className="flex flex-wrap gap-1">
          {POWER_ACTIONS.map((action) => (
            <Button
              key={action}
              size="sm"
              variant={action === 'stop' ? 'danger' : 'ghost'}
              disabled={! vm.is_operable}
              loading={power.isPending && power.variables.id === vm.id && power.variables.action === action}
              onClick={() => { power.mutate({
                  id: vm.id,
                  action,
                  // A fresh key per press: two deliberate reboots are two
                  // operations, and only a retry of the same press should
                  // collapse into one.
                  idempotency_key: crypto.randomUUID(),
                }); }
              }
            >
              {t(`vps.actions.${action}`)}
            </Button>
          ))}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.vps')} description={t('vps.subtitle')} />

      <LoadFailure error={readError} />

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
              caption={t('nav.vps')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(vm) => vm.id}
              empty={t('vps.empty')}
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
