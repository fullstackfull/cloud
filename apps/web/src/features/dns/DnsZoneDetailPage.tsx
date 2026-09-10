import { useTranslation } from 'react-i18next'
import { Link, Outlet, useNavigate, useParams } from 'react-router'

import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DangerAction, DangerZone } from '@/components/DangerZone'
import { FactList, type Fact } from '@/components/FactList'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { ResourceHeader } from '@/components/ResourceHeader'
import { ResourceTabs, type ResourceTab } from '@/components/ResourceTabs'
import { StatusBadge } from '@/components/StatusBadge'
import { useResource, type ResourceOutlet } from '@/features/resources/outlet'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useDnsZone, useReleaseDnsZone } from '@/lib/queries'
import type { DnsZone } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { useState } from 'react'

import { ZoneDelegation } from './ZoneDelegation'
import { ZoneRecords } from './ZoneRecords'
import { ZoneTransfer } from './ZoneTransfer'

/**
 * One zone, in one place.
 *
 * Addressed by the zone's name, like a domain, because that is what a customer
 * recognises. The zone the audit found buried behind a select on a screen that
 * held every zone at once now has its own address, so "the records of
 * example.com" is a link somebody can send.
 *
 * The delegation stays first, above the records, for the reason it always was:
 * a zone here serves nothing until the domain is delegated to these
 * nameservers at the registrar, this platform cannot check that the account
 * owns the domain, and nothing here pretends to.
 */
const TABS: readonly ResourceTab[] = [
  { to: '', labelKey: 'resource.overview' },
  { to: 'records', labelKey: 'dns.records' },
  { to: 'transfer', labelKey: 'dns.transfer.title' },
  { to: 'danger', labelKey: 'resource.dangerZone' },
]

export function DnsZoneDetailPage() {
  const { t } = useTranslation()
  const { identity = '' } = useParams()
  const { data: zone, isPending, error } = useDnsZone(identity)

  if (isPending) return <Loading />

  if (zone === undefined) {
    return <LoadFailure error={error} />
  }

  return (
    <>
      <ResourceHeader
        crumbs={[{ label: t('nav.dns'), to: '/dns' }, { label: zone.name }]}
        identity={zone.name}
        family={t('nav.dns')}
        badges={
          <>
            <StatusBadge status={zone.state} />
            {zone.needs_attention ? <Badge tone="warning">{t('domains.attention')}</Badge> : null}
          </>
        }
      />

      <ResourceTabs base={`/dns/${encodeURIComponent(zone.name)}`} tabs={TABS} />

      <Outlet context={{ resource: zone } satisfies ResourceOutlet<DnsZone>} />
    </>
  )
}

export function DnsZoneOverviewSection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const zone = useResource<DnsZone>()

  const facts: Fact[] = [
    { label: t('dns.zone'), value: zone.name, ltr: true },
    { label: t('dns.state'), value: <StatusBadge status={zone.state} /> },
    { label: t('dns.recordCount'), value: zone.record_count ?? null },
    {
      label: t('dns.lastSynced'),
      value: zone.last_synced_at === null ? null : formatDateTime(zone.last_synced_at, locale),
      hint: t('dns.lastSyncedHint'),
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <ZoneDelegation zone={zone} />

      <Card title={t('resource.overview')}>
        <FactList facts={facts} />
      </Card>

      <Card title={t('resource.related')} description={t('dns.relatedBody')}>
        <div className="flex flex-wrap gap-4 text-sm">
          <Link to={`/domains/${encodeURIComponent(zone.name)}`} className="underline">
            {t('dns.openDomain')}
          </Link>
          <Link to="/support" className="underline">
            {t('nav.support')}
          </Link>
        </div>
      </Card>
    </div>
  )
}

export function DnsZoneRecordsSection() {
  const zone = useResource<DnsZone>()

  return <ZoneRecords zone={zone} />
}

export function DnsZoneTransferSection() {
  const zone = useResource<DnsZone>()

  return <ZoneTransfer key={zone.id} zone={zone} />
}

/**
 * Giving up a zone.
 *
 * The one destructive act available on a zone, and the only thing in this
 * danger zone. It keeps the typed zone name Wave 0 gave it: every record goes
 * with the zone, and whatever resolves through them stops.
 */
export function DnsZoneDangerSection() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const navigate = useNavigate()
  const zone = useResource<DnsZone>()

  const release = useReleaseDnsZone()
  const [releasing, setReleasing] = useState(false)

  const failure = describeError(release.error)

  return (
    <>
      <DangerZone>
        <DangerAction
          title={t('dns.releaseTitle')}
          body={t('dns.releaseExplainer')}
          action={
            <Button
              variant="danger"
              disabled={zone.is_being_deleted}
              onClick={() => { setReleasing(true); }}
            >
              {t('dns.release')}
            </Button>
          }
        />
      </DangerZone>

      {releasing ? (
        <ConfirmDialog
          open
          title={t('dns.releaseTitle')}
          body={t('dns.releaseWarning', { zone: zone.name })}
          requiredPhrase={zone.name}
          requiredPhraseLabel={t('dns.releaseConfirmLabel', { zone: zone.name })}
          confirmLabel={t('dns.release')}
          loading={release.isPending}
          {...(failure === null ? {} : { error: failure.message })}
          onCancel={() => {
            setReleasing(false)
            release.reset()
          }}
          onConfirm={(confirmation) => {
            release.mutate(
              { zoneId: zone.id, confirmation },
              {
                onSuccess: () => {
                  setReleasing(false)
                  // The zone this page is about no longer exists, so staying
                  // here would show a 404 where a zone used to be.
                  void navigate('/dns')
                },
              },
            )
          }}
        />
      ) : null}
    </>
  )
}
