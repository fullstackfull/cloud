import { useTranslation } from 'react-i18next'
import { Link, Outlet, useParams } from 'react-router'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
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
import { useHostingAccount, useWordPressSite } from '@/lib/queries'
import type { WordPressSite } from '@/lib/types'

import { SiteAdvice, SiteSteps } from './SiteSteps'
import { SiteCopies } from './SiteCopies'

/**
 * One WordPress site, in one place.
 *
 * The capabilities the audit found scattered down a list of cards are grouped
 * here without changing any of their semantics: the copies section is the same
 * component with the same impact preview, the same typed production domain, the
 * same one-operation-at-a-time behaviour and the same treatment of an operation
 * the platform did not hear the end of.
 *
 * A site is billed as the hosting it runs on, so the billing and activity
 * sections read that account's service. There is no separate WordPress
 * subscription to show and inventing one would misdescribe the sale. The panel
 * itself is still reached through the hosting account: this platform has no
 * WordPress adapter of its own and does not pretend to one.
 */
const TABS: readonly ResourceTab[] = [
  { to: '', labelKey: 'resource.overview' },
  { to: 'copies', labelKey: 'wordpress.copies.title' },
  { to: 'activity', labelKey: 'resource.activity' },
  { to: 'billing', labelKey: 'resource.billing' },
]

export function WordPressDetailPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const { data: site, isPending, error } = useWordPressSite(id)

  if (isPending) return <Loading />

  if (site === undefined) {
    return <LoadFailure error={error} />
  }

  return (
    <>
      <ResourceHeader
        crumbs={[
          { label: t('nav.services'), to: '/services' },
          { label: t('nav.wordpress'), to: '/wordpress' },
          { label: site.domain },
        ]}
        identity={site.domain}
        family={t('nav.wordpress')}
        badges={
          <>
            <StatusBadge status={site.state} />
            {site.kind === 'production' ? null : (
              <Badge tone={site.kind === 'staging' ? 'info' : 'neutral'}>
                {t(`wordpress.kinds.${site.kind}`)}
              </Badge>
            )}
          </>
        }
        actions={
          /*
           * A link and not a button: it navigates away, to the site's own
           * administration screen. Offered only for a site this platform has
           * fetched and had WordPress answer — sending somebody to the login
           * page of a site that is still being built is a dead end.
           */
          site.is_usable && site.admin_url !== null ? (
            <a
              href={site.admin_url}
              target="_blank"
              rel="noreferrer noopener"
              dir="ltr"
              className="inline-flex items-center rounded border border-[var(--border)] px-2 py-1 text-xs text-[var(--text-primary)] hover:bg-[var(--surface-sunken)]"
            >
              {t('wordpress.openAdmin')}
            </a>
          ) : undefined
        }
      />

      <ResourceTabs base={`/wordpress/${site.id}`} tabs={TABS} />

      <Outlet context={{ resource: site } satisfies ResourceOutlet<WordPressSite>} />
    </>
  )
}

export function WordPressOverviewSection() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const site = useResource<WordPressSite>()

  const facts: Fact[] = [
    { label: t('wordpress.domain'), value: site.domain, ltr: true },
    { label: t('wordpress.domainSource'), value: t(`wordpress.sources.${site.domain_source}`) },
    { label: t('services.state'), value: <StatusBadge status={site.state} /> },
    { label: t('wordpress.version'), value: site.wordpress_version, ltr: true },
    { label: t('wordpress.adminUsername'), value: site.admin_username, ltr: true },
    {
      label: t('wordpress.verifiedAt'),
      value: site.verified_at === null ? null : formatDate(site.verified_at, locale),
      hint: t('wordpress.verifiedExplainer'),
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <Card title={t('wordpress.progress')} description={t('wordpress.progressBody')}>
        <SiteSteps site={site} />

        <div className="mt-4">
          <SiteAdvice site={site} />
        </div>
      </Card>

      <Card title={t('resource.overview')}>
        <FactList facts={facts} />
      </Card>

      <Card title={t('resource.related')} description={t('wordpress.relatedBody')}>
        <div className="flex flex-wrap gap-4 text-sm">
          {site.hosting_account_id === null ? null : (
            <Link to={`/hosting/${site.hosting_account_id}`} className="underline">
              {t('wordpress.openHosting')}
            </Link>
          )}
          {site.domain_id === null ? null : (
            <Link to={`/domains/${encodeURIComponent(site.domain)}`} className="underline">
              {t('wordpress.openDomain')}
            </Link>
          )}
          <Link to="/support" className="underline">
            {t('nav.support')}
          </Link>
        </div>
      </Card>
    </div>
  )
}

export function WordPressCopiesSection() {
  const { t } = useTranslation()
  const site = useResource<WordPressSite>()

  return (
    <Card title={t('wordpress.copies.title')} description={t('wordpress.copies.body')}>
      <SiteCopies site={site} />
    </Card>
  )
}

/**
 * The service behind a site, which is the hosting account it runs on.
 *
 * Two requests rather than one because the site row carries the account's id
 * and not the service's, and only the sections that need the chain pay for it.
 */
function useSiteServiceId(site: WordPressSite): string | null {
  const { data: account } = useHostingAccount(site.hosting_account_id)

  return account?.service_id ?? null
}

export function WordPressActivitySection() {
  const site = useResource<WordPressSite>()

  return <ResourceActivity serviceId={useSiteServiceId(site)} />
}

export function WordPressBillingSection() {
  const site = useResource<WordPressSite>()

  return <ResourceBillingPanel serviceId={useSiteServiceId(site)} />
}
