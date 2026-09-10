import { useTranslation } from 'react-i18next'
import { Link, Outlet, useParams } from 'react-router'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { DangerAction, DangerZone } from '@/components/DangerZone'
import { DataTable, type Column } from '@/components/DataTable'
import { FactList, type Fact } from '@/components/FactList'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { ResourceHeader } from '@/components/ResourceHeader'
import { ResourceTabs, type ResourceTab } from '@/components/ResourceTabs'
import { StatusBadge } from '@/components/StatusBadge'
import { BackupsForMachine } from '@/features/backups/BackupsForMachine'
import { ResourceActivity } from '@/features/resources/ResourceActivity'
import { ResourceBillingPanel } from '@/features/resources/ResourceBillingPanel'
import { useResource, type ResourceOutlet } from '@/features/resources/outlet'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useVirtualMachine } from '@/lib/queries'
import type { VirtualMachine } from '@/lib/types'

import { VpsPowerActions } from './vps/VpsPowerActions'
import { VpsReinstallAction } from './vps/VpsReinstallAction'

/**
 * One machine, in one place.
 *
 * The audit's AR-7: there was no page for a server. Its power controls lived
 * in a table row, its backups on a different screen behind a picker, its
 * addresses on a third, its plan and its price on a fourth, and its history
 * nowhere at all. A customer could not answer "what is this, is it healthy,
 * what does it cost, what can I do to it" without visiting four screens and
 * joining them by eye.
 *
 * The sections are routes rather than ARIA tabs, because they are addresses: a
 * customer sends "the backups tab of web-01" to a colleague, refreshes it and
 * bookmarks it. Each section fetches only what it needs, on the visit that
 * needs it — the overview costs one request, and nobody who came for the
 * hostname pays for the billing chain.
 *
 * Every action on this page is the same component the list row uses, so there
 * is one mutation path per act: the audit's own warning about resource pages
 * was that two UI paths become two implementations.
 */
const TABS: readonly ResourceTab[] = [
  { to: '', labelKey: 'resource.overview' },
  { to: 'networking', labelKey: 'resource.networking' },
  { to: 'backups', labelKey: 'nav.backups' },
  { to: 'activity', labelKey: 'resource.activity' },
  { to: 'billing', labelKey: 'resource.billing' },
  { to: 'danger', labelKey: 'resource.dangerZone' },
]

export function VpsDetailPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const { data: vm, isPending, error } = useVirtualMachine(id)

  if (isPending) return <Loading />

  if (vm === undefined) {
    return <LoadFailure error={error} />
  }

  return (
    <>
      <ResourceHeader
        crumbs={[
          { label: t('nav.services'), to: '/services' },
          { label: t('nav.vps'), to: '/vps' },
          { label: vm.hostname },
        ]}
        identity={vm.hostname}
        family={t('nav.vps')}
        badges={
          <>
            <StatusBadge status={vm.power_state} />
            {vm.service_status === 'active' ? null : <Badge tone="warning">{t(`status.${vm.service_status}`, { defaultValue: vm.service_status })}</Badge>}
            {vm.actions.blocked_reason === null ? null : (
              <span className="text-xs text-[var(--text-secondary)]">
                {t(`vps.blocked.${vm.actions.blocked_reason}`, {
                  defaultValue: t('vps.blocked.default'),
                })}
              </span>
            )}
          </>
        }
        facts={
          <>
            <span>
              {vm.resources.vcpu} {t('resources.vcpu')}
            </span>
            <span>{(vm.resources.memory_mib / 1024).toFixed(0)} GiB RAM</span>
            <span>{vm.resources.disk_gib} GiB</span>
          </>
        }
        actions={
          <>
            <VpsPowerActions vm={vm} />
            <Link
              to={`/vps/${vm.id}/console`}
              className="inline-flex items-center rounded border border-[var(--border)] px-2 py-1 text-xs text-[var(--text-primary)] hover:bg-[var(--surface-sunken)]"
            >
              {t('vps.actions.console')}
            </Link>
          </>
        }
      />

      <ResourceTabs base={`/vps/${vm.id}`} tabs={TABS} />

      <Outlet context={{ resource: vm } satisfies ResourceOutlet<VirtualMachine>} />
    </>
  )
}

export function VpsOverviewSection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const vm = useResource<VirtualMachine>()

  const facts: Fact[] = [
    { label: t('vps.hostname'), value: vm.hostname, ltr: true },
    { label: t('vps.power'), value: <StatusBadge status={vm.power_state} /> },
    { label: t('services.state'), value: <StatusBadge status={vm.service_status} /> },
    { label: t('resources.vcpu'), value: vm.resources.vcpu },
    { label: 'RAM', value: `${(vm.resources.memory_mib / 1024).toFixed(0)} GiB` },
    { label: t('resources.disk_gib'), value: `${vm.resources.disk_gib} GiB` },
    {
      /*
       * The image, where the platform recorded one. A machine built before
       * the platform tracked its template has none, and the honest answer is
       * "not available" rather than a plausible-looking distribution.
       */
      label: t('vps.os'),
      value:
        vm.os_family === null
          ? null
          : `${vm.os_family}${vm.os_version === null ? '' : ` ${vm.os_version}`}`,
      ltr: true,
    },
    {
      label: t('vps.primaryAddress'),
      value: vm.addresses.find((address) => address.is_primary)?.address ?? null,
      ltr: true,
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <Card title={t('resource.overview')}>
        <FactList facts={facts} />
      </Card>

      {vm.reinstall === null ? null : (
        <Card title={t('vps.rebuild')} description={t('vps.rebuildBody')}>
          <FactList
            columns={2}
            facts={[
              { label: t('services.state'), value: <StatusBadge status={vm.reinstall.state} /> },
              {
                label: t('vps.rebuildRequested'),
                value: formatDate(vm.reinstall.requested_at, locale),
              },
            ]}
          />
        </Card>
      )}
    </div>
  )
}

export function VpsNetworkingSection() {
  const { t } = useTranslation()
  const vm = useResource<VirtualMachine>()

  const columns: Array<Column<VirtualMachine['addresses'][number]>> = [
    {
      key: 'address',
      header: t('ips.address'),
      ltr: true,
      cell: (address) => <span className="technical">{address.address}</span>,
    },
    {
      key: 'version',
      header: t('ips.version'),
      cell: (address) => `IPv${address.ip_version}`,
    },
    {
      key: 'primary',
      header: t('ips.primary'),
      cell: (address) => (address.is_primary ? t('common.yes') : t('common.no')),
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <Card
        title={t('resource.networking')}
        description={t('resource.networkingBody')}
        actions={
          <Link to="/ips" className="text-sm underline">
            {t('nav.ips')}
          </Link>
        }
      >
        <DataTable
          caption={t('resource.networking')}
          columns={columns}
          rows={vm.addresses}
          rowKey={(address) => address.address}
          empty={t('vps.noAddresses')}
        />
      </Card>
    </div>
  )
}

export function VpsBackupsSection() {
  const vm = useResource<VirtualMachine>()

  return <BackupsForMachine vm={vm} />
}

export function VpsActivitySection() {
  const vm = useResource<VirtualMachine>()

  return <ResourceActivity serviceId={vm.service_id} />
}

export function VpsBillingSection() {
  const vm = useResource<VirtualMachine>()

  return <ResourceBillingPanel serviceId={vm.service_id} />
}

export function VpsDangerSection() {
  const { t } = useTranslation()
  const vm = useResource<VirtualMachine>()

  return (
    <DangerZone>
      <DangerAction
        title={t('vps.actions.reinstall')}
        body={t('vps.reinstall.advice')}
        action={<VpsReinstallAction vm={vm} />}
      />
    </DangerZone>
  )
}
