import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useVirtualMachines } from '@/lib/queries'
import type { VirtualMachine } from '@/lib/types'
import { safeLabel } from '@/lib/safeLabel'
import { useUrlPage } from '@/lib/urlState'

import { VpsPowerActions } from './vps/VpsPowerActions'

export function VpsPage() {
  const { t } = useTranslation()
  // W5.7: in the address bar rather than in component state, so a refresh
  // stays on this page and Back returns to it from whatever the customer
  // opened. The one mechanism is in `useUrlPage`.
  const [page, setPage] = useUrlPage()
  const { data, isPending, error: readError } = useVirtualMachines(page)

  const columns: Array<Column<VirtualMachine>> = [
    {
      key: 'hostname',
      header: t('vps.hostname'),
      ltr: true,
      /*
       * The way in. Since Wave 3 the row is an index entry: the machine has a
       * page of its own, and the hostname is what a customer clicks to reach
       * it.
       */
      cell: (vm) => (
        <Link to={`/vps/${vm.id}`} className="technical font-medium underline">
          {vm.hostname}
        </Link>
      ),
    },
    {
      key: 'spec',
      header: t('vps.spec'),
      ltr: true,
      cell: (vm) => (
        <span className="technical text-xs">
          {vm.resources.vcpu} vCPU · {Math.round(vm.resources.memory_mib / 1024)} GiB · {vm.resources.disk_gib} GiB
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
    {
      key: 'power',
      header: t('vps.power'),
      cell: (vm) => (
        <div className="flex flex-col items-start gap-1">
          <StatusBadge status={vm.power_state} />
          {/*
            * The service's own state, shown only when it is not active.
            *
            * Every control on this row is disabled for a machine whose service
            * is suspended or halfway through being reactivated — correctly,
            * because the API refuses them — and until now the screen gave no
            * reason for it. A customer whose subscription lapsed saw a row of
            * dead buttons and nothing that said why, which reads as a broken
            * portal rather than as a suspension they can fix by paying.
            */}
          {vm.service_status === 'active' ? null : (
            <Badge tone={vm.service_status === 'reactivating' ? 'warning' : 'danger'}>
              {safeLabel('vps.serviceState', vm.service_status)}
            </Badge>
          )}
          {/*
            * Why the buttons on this row are off, in the customer's words.
            *
            * Published by the API from the same facts its guard refuses on,
            * so the row never offers a control the endpoint would answer 409
            * to — the case that used to happen was a machine whose last
            * rebuild timed out: operable, yet every operation refused until a
            * person had looked, with an enabled Reinstall button saying
            * otherwise. Not shown for an inactive service, which the badge
            * above already explains.
            */}
          {vm.actions.blocked_reason === null || vm.actions.blocked_reason === 'service_not_active' ? null : (
            <span className="text-xs text-[var(--warning-text)]">
              {safeLabel('vps.blocked', vm.actions.blocked_reason)}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'rebuild',
      header: t('vps.rebuild'),
      cell: (vm) =>
        vm.reinstall === null ? (
          <span className="text-xs text-[var(--text-muted)]">—</span>
        ) : (
          <span
            className={
              vm.reinstall.needs_attention
                ? 'text-xs text-[var(--danger-text)]'
                : 'text-xs text-[var(--text-muted)]'
            }
          >
            {safeLabel('vps.reinstallState', vm.reinstall.state)}
          </span>
        ),
    },
    {
      key: 'actions',
      header: '',
      /*
       * Two controls, where there were six.
       *
       * The audit found eight buttons squeezed into one row, none of them
       * reachable on a phone. Nothing was removed: every power control, the
       * console and the rebuild live on the machine's page, which is one click
       * away and has room to explain them. What stays here is the way in and
       * the one action customers take most — and the reboot is the same
       * component the page uses, so there is one request behind both.
       */
      cell: (vm) => (
        <div className="flex flex-wrap justify-end gap-2">
          <Link
            to={`/vps/${vm.id}`}
            className="inline-flex items-center rounded border border-[var(--border)] px-2 py-1 text-xs text-[var(--text-primary)] hover:bg-[var(--surface-sunken)]"
          >
            {t('resource.open')}
          </Link>

          <VpsPowerActions vm={vm} only={['reboot']} />
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.vps')} description={t('vps.subtitle')} />

      <LoadFailure error={readError} />

      {/*
        * A power failure is reported where the button is, by the component
        * that made the request, rather than in a banner at the top of a table
        * that says nothing about which row it came from.
        */}
      <Card>
        {isPending ? (
          <Loading />
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
