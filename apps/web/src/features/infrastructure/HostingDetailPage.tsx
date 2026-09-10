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
import { useHostingAccount } from '@/lib/queries'
import type { HostingAccount } from '@/lib/types'

import { HostingPanelButton } from './hosting/HostingPanelButton'
import { panelNameKey } from './hosting/panelName'
import { HostingUsageCard } from './hosting/HostingUsageCard'

/**
 * One hosting account, in one place.
 *
 * The three things the audit found a customer could not answer about their
 * hosting are the three things this page leads with: which plan it is (the
 * catalogue name, not the join key), which panel they are about to sign into,
 * and how much of their allowance they have used — as a real reading with the
 * date the platform last heard, never a fabricated figure and never a zero
 * standing in for "not measured".
 *
 * The node is not on this page. Which shared machine an account sits on is the
 * operator's business, and the resource the API publishes does not carry it.
 */
const TABS: readonly ResourceTab[] = [
  { to: '', labelKey: 'resource.overview' },
  { to: 'activity', labelKey: 'resource.activity' },
  { to: 'billing', labelKey: 'resource.billing' },
]

export function HostingDetailPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const { data: account, isPending, error } = useHostingAccount(id)

  if (isPending) return <Loading />

  if (account === undefined) {
    return <LoadFailure error={error} />
  }

  return (
    <>
      <ResourceHeader
        crumbs={[
          { label: t('nav.services'), to: '/services' },
          { label: t('nav.hosting'), to: '/hosting' },
          { label: account.primary_domain ?? account.username },
        ]}
        identity={account.primary_domain ?? account.username}
        family={t('nav.hosting')}
        badges={
          <>
            <StatusBadge status={account.status} />
            {account.package?.plan_name === null || account.package === null ? null : (
              <Badge>{account.package.plan_name}</Badge>
            )}
          </>
        }
        facts={
          <>
            <span dir="ltr">{account.username}</span>
            <span>{t(panelNameKey(account.panel_type))}</span>
          </>
        }
        actions={<HostingPanelButton account={account} />}
      />

      <ResourceTabs base={`/hosting/${account.id}`} tabs={TABS} />

      <Outlet context={{ resource: account } satisfies ResourceOutlet<HostingAccount>} />
    </>
  )
}

export function HostingOverviewSection() {
  const { t } = useTranslation()
  const account = useResource<HostingAccount>()

  const pkg = account.package

  const facts: Fact[] = [
    {
      /*
       * The plan's own name, from the catalogue, in the reader's language.
       * The slug is a join key and the audit found it on screen where a plan
       * name belonged; it is kept beside the name as a hint for anybody
       * quoting it to support rather than as the answer.
       */
      label: t('hosting.package'),
      value: pkg?.plan_name ?? null,
      ...(pkg === null ? {} : { hint: pkg.slug }),
    },
    {
      label: t('hosting.panelType'),
      value: t(panelNameKey(account.panel_type)),
      ...(account.panel_type === null ? { hint: t('hosting.panelUnknownHint') } : {}),
    },
    { label: t('hosting.username'), value: account.username, ltr: true },
    { label: t('hosting.domain'), value: account.primary_domain, ltr: true },
    { label: t('hosting.status'), value: <StatusBadge status={account.status} /> },
    {
      label: t('hosting.diskQuotaLabel'),
      value: quota(pkg?.disk_quota_mib ?? null, t('hosting.unlimited'), pkg !== null),
    },
    {
      label: t('hosting.bandwidthQuotaLabel'),
      value: quota(pkg?.bandwidth_quota_mib ?? null, t('hosting.unlimited'), pkg !== null),
    },
    { label: t('hosting.addonDomains'), value: pkg?.max_addon_domains ?? null },
    { label: t('hosting.databases'), value: pkg?.max_databases ?? null },
    { label: t('hosting.mailboxes'), value: pkg?.max_email_accounts ?? null },
  ]

  return (
    <div className="flex flex-col gap-6">
      <Card title={t('resource.overview')}>
        <FactList facts={facts} />
      </Card>

      <HostingUsageCard accountId={account.id} />

      <Card title={t('resource.related')} description={t('hosting.relatedBody')}>
        <div className="flex flex-wrap gap-4 text-sm">
          <Link to="/wordpress" className="underline">
            {t('nav.wordpress')}
          </Link>
          <Link to="/dns" className="underline">
            {t('nav.dns')}
          </Link>
          <Link to="/support" className="underline">
            {t('nav.support')}
          </Link>
        </div>
      </Card>
    </div>
  )
}

/**
 * A ceiling, or the honest reason there is no number.
 *
 * Null with a package attached means the plan is sold without a ceiling; null
 * with no package at all means the platform cannot say, and returning "not
 * available" for that case is what keeps the two apart on screen.
 */
function quota(mib: number | null, unlimitedLabel: string, packaged: boolean): string | null {
  if (mib !== null) return `${Math.round(mib / 1024).toString()} GiB`

  return packaged ? unlimitedLabel : null
}

export function HostingActivitySection() {
  const account = useResource<HostingAccount>()

  return <ResourceActivity serviceId={account.service_id} />
}

export function HostingBillingSection() {
  const account = useResource<HostingAccount>()

  return <ResourceBillingPanel serviceId={account.service_id} />
}
