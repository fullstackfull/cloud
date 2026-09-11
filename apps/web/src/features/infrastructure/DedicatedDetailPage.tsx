import { useTranslation } from 'react-i18next'
import { Outlet, useParams } from 'react-router'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { DangerAction, DangerZone } from '@/components/DangerZone'
import { FactList, type Fact } from '@/components/FactList'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { ResourceHeader } from '@/components/ResourceHeader'
import { ResourceTabs, type ResourceTab } from '@/components/ResourceTabs'
import { StatusBadge } from '@/components/StatusBadge'
import { ResourceActivity } from '@/features/resources/ResourceActivity'
import { ResourceBillingPanel } from '@/features/resources/ResourceBillingPanel'
import { useResource, type ResourceOutlet } from '@/features/resources/outlet'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useDedicatedServer } from '@/lib/queries'
import type { DedicatedServer } from '@/lib/types'

import { DedicatedPowerActions } from './dedicated/DedicatedPowerActions'
import { DedicatedReinstallAction } from './dedicated/DedicatedReinstallAction'

/**
 * One dedicated machine, in one place.
 *
 * What the customer is shown is what they bought: the manufacturer, the model,
 * the hardware profile they chose, the serial they can read off an invoice, the
 * chassis power state and the rebuild history. What they are not shown, here or
 * anywhere: the management address, the BMC or iLO, the rack and the datacentre
 * position, the credential the platform uses to reach the chassis. Those are
 * the operator's, and the resource the API publishes does not carry them.
 *
 * There is no networking section. A dedicated machine's addresses live on the
 * IP addresses screen, which is where the platform models them; inventing a
 * section here would mean inventing the data behind it.
 */
const TABS: readonly ResourceTab[] = [
  { to: '', labelKey: 'resource.overview' },
  { to: 'activity', labelKey: 'resource.activity' },
  { to: 'billing', labelKey: 'resource.billing' },
  { to: 'danger', labelKey: 'resource.dangerZone' },
]

export function DedicatedDetailPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const { data: server, isPending, error } = useDedicatedServer(id)

  if (isPending) return <Loading />

  if (server === undefined) {
    return <LoadFailure error={error} />
  }

  return (
    <>
      <ResourceHeader
        crumbs={[
          { label: t('nav.services'), to: '/services' },
          { label: t('nav.dedicated'), to: '/dedicated' },
          { label: server.serial },
        ]}
        identity={server.serial}
        family={t('nav.dedicated')}
        badges={
          <>
            <StatusBadge status={server.power_state} />
            {server.status === 'active' ? null : (
              <Badge tone="warning">
                {t(`status.${server.status}`, { defaultValue: server.status })}
              </Badge>
            )}
          </>
        }
        facts={
          <>
            {/*
              The machine as it would be described on a delivery note. The
              hardware profile used to sit beside it and was the inventory
              join key: what a customer read here was `ded-standard-1`, in
              English, on an Arabic page, styled as a fact about their server.
            */}
            <span dir="ltr">
              {server.manufacturer} {server.model}
            </span>
          </>
        }
        actions={<DedicatedPowerActions server={server} />}
      />

      <ResourceTabs base={`/dedicated/${server.id}`} tabs={TABS} />

      <Outlet context={{ resource: server } satisfies ResourceOutlet<DedicatedServer>} />
    </>
  )
}

export function DedicatedOverviewSection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const server = useResource<DedicatedServer>()

  const facts: Fact[] = [
    { label: t('dedicated.serial'), value: server.serial, ltr: true },
    { label: t('dedicated.manufacturer'), value: server.manufacturer, ltr: true },
    { label: t('dedicated.model'), value: server.model, ltr: true },
    { label: t('dedicated.status'), value: <StatusBadge status={server.status} /> },
    { label: t('dedicated.power'), value: <StatusBadge status={server.power_state} /> },
    {
      label: t('dedicated.since'),
      value: server.activated_at == null ? null : formatDate(server.activated_at, locale),
      hint: t('dedicated.sinceHint'),
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <Card title={t('resource.overview')}>
        <FactList facts={facts} />
      </Card>

      {server.reinstall === null ? null : (
        <Card title={t('dedicated.rebuild')} description={t('dedicated.rebuildBody')}>
          <FactList
            columns={2}
            facts={[
              {
                label: t('services.state'),
                value: t(`dedicated.reinstallState.${server.reinstall.state}`, {
                  defaultValue: server.reinstall.state,
                }),
              },
              {
                label: t('dedicated.rebuildRequested'),
                value: formatDate(server.reinstall.requested_at, locale),
              },
            ]}
          />

          {/*
            * The one thing a customer must be told about a rebuild that did
            * not finish: whether the disks are already gone. It is true from
            * the moment the machine was told to boot into an installer,
            * including when the platform never heard back, so it is read
            * before anything reassuring is said.
            */}
          {server.reinstall.data_destroyed ? (
            <p className="mt-4 text-sm text-[var(--danger-text)]">
              {t('dedicated.rebuildDataDestroyed')}
            </p>
          ) : null}

          {server.reinstall.needs_attention ? (
            <p className="mt-2 text-sm text-[var(--warning-text)]">
              {t('dedicated.rebuildNeedsAttention')}
            </p>
          ) : null}
        </Card>
      )}
    </div>
  )
}

export function DedicatedActivitySection() {
  const server = useResource<DedicatedServer>()

  return <ResourceActivity serviceId={server.service_id} />
}

export function DedicatedBillingSection() {
  const server = useResource<DedicatedServer>()

  return <ResourceBillingPanel serviceId={server.service_id} />
}

export function DedicatedDangerSection() {
  const { t } = useTranslation()
  const server = useResource<DedicatedServer>()

  return (
    <DangerZone>
      <DangerAction
        title={t('dedicated.actions.reinstall')}
        body={t('dedicated.reinstall.advice')}
        action={<DedicatedReinstallAction server={server} />}
      />
    </DangerZone>
  )
}
