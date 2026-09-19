import { useTranslation } from 'react-i18next'
import { Link, Outlet, useParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { FactList, type Fact } from '@/components/FactList'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { ResourceHeader } from '@/components/ResourceHeader'
import { ResourceTabs, type ResourceTab } from '@/components/ResourceTabs'
import { StatusBadge } from '@/components/StatusBadge'
import { useResource, type ResourceOutlet } from '@/features/resources/outlet'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useDomain } from '@/lib/queries'
import type { Domain } from '@/lib/types'

import { DomainAutoRenewToggle } from './DomainAutoRenewToggle'
import { DomainContactsForm } from './DomainContactsForm'
import { DomainLeavingPanel } from './DomainLeavingPanel'
import { DomainNameserversForm } from './DomainNameserversForm'
import { DomainRenewAction } from './DomainRenewAction'
import { RedemptionPanel } from './RedemptionPanel'

/**
 * One name, in one place.
 *
 * Addressed by the name itself — `/domains/example.com` — because that is what
 * a customer recognises, reads out to support and finds in their own history.
 * The API accepts either form and resolves it through the acting customer, so
 * a name somebody else holds is not found rather than found-and-refused.
 *
 * There is no activity section and no billing section here, and that is not an
 * omission. A domain is not a provisioned service: it has no service row, no
 * subscription and no events endpoint. Renewals raise invoices, and those are
 * linked from the renewal itself rather than from a fabricated chain.
 */
const TABS: readonly ResourceTab[] = [
  { to: '', labelKey: 'resource.overview' },
  { to: 'nameservers', labelKey: 'domains.nameservers' },
  { to: 'contacts', labelKey: 'domains.contacts' },
  { to: 'transfer', labelKey: 'domains.transferOut' },
]

export function DomainDetailPage() {
  const { t } = useTranslation()
  const { identity = '' } = useParams()
  const { data: domain, isPending, error } = useDomain(identity)

  if (isPending) return <Loading />

  if (domain === undefined) {
    return <LoadFailure error={error} />
  }

  return (
    <>
      <ResourceHeader
        crumbs={[
          { label: t('nav.domains'), to: '/domains' },
          { label: domain.name },
        ]}
        identity={domain.name}
        family={t('nav.domains')}
        badges={
          <>
            <StatusBadge status={domain.state} />
            {domain.is_expiring ? <Badge tone="warning">{t('domains.expiringSoon')}</Badge> : null}
            {domain.is_held ? <Badge tone="warning">{t('domains.held')}</Badge> : null}
          </>
        }
        /*
         * No actions in the header, deliberately. Renewing is the one thing a
         * customer does to a name they hold, and it belongs in the renewal
         * section beside the price it costs and the switch that automates it —
         * offering it twice on one page is the duplication this wave exists to
         * remove.
         */
      />

      <ResourceTabs base={`/domains/${encodeURIComponent(domain.name)}`} tabs={TABS} />

      <Outlet context={{ resource: domain } satisfies ResourceOutlet<Domain>} />
    </>
  )
}

export function DomainOverviewSection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const domain = useResource<Domain>()

  const facts: Fact[] = [
    { label: t('domains.name'), value: domain.name, ltr: true },
    { label: t('services.state'), value: <StatusBadge status={domain.state} /> },
    {
      label: t('domains.term'),
      value: t('domains.termYears', { count: domain.term_years, years: domain.term_years }),
    },
    {
      label: t('domains.registered'),
      value: domain.registered_at === null ? null : formatDate(domain.registered_at, locale),
    },
    {
      label: t('domains.expires'),
      value: domain.expires_at === null ? null : formatDate(domain.expires_at, locale),
    },
    {
      label: t('domains.transferLock'),
      value:
        domain.transfer_locked === null
          ? null
          : domain.transfer_locked
            ? t('common.yes')
            : t('common.no'),
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      {domain.needs_attention ? (
        /*
         * The Timeout Rule reaching the customer. A name the platform could
         * not confirm must not be presented as working, and must not offer a
         * retry: the money may already have moved.
         */
        <Alert tone="warning">{t('domains.needsAttention')}</Alert>
      ) : null}

      <Card title={t('resource.overview')}>
        <FactList facts={facts} />
      </Card>

      <Card title={t('domains.renewal')} description={t('domains.renewalBody')}>
        <div className="flex flex-col gap-4">
          <DomainAutoRenewToggle domain={domain} />

          <div className="border-t border-[var(--border-subtle)] pt-4">
            <DomainRenewAction domain={domain} />
          </div>
        </div>
      </Card>

      {domain.redemption === null ? null : (
        <RedemptionPanel domain={domain} locale={locale} />
      )}

      <Card title={t('resource.related')} description={t('domains.relatedBody')}>
        <div className="flex flex-wrap gap-4 text-sm">
          {domain.dns_zone_id === null ? (
            <Link to="/dns" className="underline">
              {t('domains.claimZone')}
            </Link>
          ) : (
            <Link to={`/dns/${encodeURIComponent(domain.name)}`} className="underline">
              {t('domains.openZone')}
            </Link>
          )}
          <Link to="/invoices" className="underline">
            {t('nav.invoices')}
          </Link>
          <Link to="/support" className="underline">
            {t('nav.support')}
          </Link>
        </div>
      </Card>
    </div>
  )
}

export function DomainNameserversSection() {
  const { t } = useTranslation()
  const domain = useResource<Domain>()

  return (
    <div className="flex flex-col gap-6">
      <Card title={t('domains.nameservers')} description={t('domains.nameserversBody')}>
        <DomainNameserversForm domain={domain} />
      </Card>

      {domain.dns_zone_id === null ? null : (
        <Card title={t('nav.dns')} description={t('domains.zoneBody')}>
          <Link to={`/dns/${encodeURIComponent(domain.name)}`} className="text-sm underline">
            {t('domains.openZone')}
          </Link>
        </Card>
      )}
    </div>
  )
}

export function DomainContactsSection() {
  const { t } = useTranslation()
  const domain = useResource<Domain>()

  return (
    <Card title={t('domains.contacts')} description={t('domains.contactsBody')}>
      <DomainContactsForm domain={domain} />
    </Card>
  )
}

export function DomainTransferSection() {
  const { t } = useTranslation()
  const domain = useResource<Domain>()

  return (
    <Card title={t('domains.transferOut')} description={t('domains.transferOutBody')}>
      <DomainLeavingPanel domain={domain} />
    </Card>
  )
}
