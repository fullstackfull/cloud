import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Loading } from '@/components/Loading'
import { useInfrastructureOverview, type InfrastructureOverview } from '@/lib/controlCenterQueries'

/**
 * The estate on one screen, and the short list of what needs a person at
 * the top — each a link to the screen where it is dealt with.
 */
export function OverviewPage() {
  const { t } = useTranslation()
  const overview = useInfrastructureOverview()

  return (
    <>
      <PageHeader title={t('admin.overview.title')} description={t('admin.overview.subtitle')} />

      {overview.isPending ? (
        <Loading />
      ) : overview.error ? (
        <LoadFailure error={overview.error} />
      ) : (
        <>
          <Attention data={overview.data.data} />
          <Estate data={overview.data.data} />
        </>
      )}
    </>
  )
}

function Attention({ data }: { data: InfrastructureOverview }) {
  const { t } = useTranslation()
  const items: Array<{ key: keyof InfrastructureOverview['attention']; to: string }> = [
    { key: 'deployments_waiting', to: '/admin/control-center/deployments' },
    { key: 'providers_enabled_not_ready', to: '/admin/control-center/providers' },
    { key: 'credentials_missing', to: '/admin/control-center/credentials' },
    { key: 'licences_expiring', to: '/admin/control-center/licences' },
    { key: 'infrastructure_drift_open', to: '/admin/drift' },
    { key: 'machines_never_classified', to: '/admin/control-center/machines' },
  ]
  const open = items.filter((item) => data.attention[item.key] > 0)

  return (
    <Card>
      <h2 className="mb-2 text-base font-semibold">{t('admin.overview.attentionHeading')}</h2>
      {open.length === 0 ? (
        <p className="text-sm text-[var(--text-muted)]">{t('admin.overview.nothingNeedsAPerson')}</p>
      ) : (
        <ul className="flex flex-col gap-2" aria-label={t('admin.overview.attentionHeading')}>
          {open.map((item) => (
            <li key={item.key}>
              <Link to={item.to} className="flex items-center gap-2 text-sm underline-offset-2 hover:underline">
                <Badge tone="danger">{data.attention[item.key]}</Badge>
                {t(`admin.overview.attention.${item.key}`)}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function Estate({ data }: { data: InfrastructureOverview }) {
  const { t } = useTranslation()

  const tiles: Array<{ key: string; to: string; total: number; rows: Array<[string, number]> }> = [
    { key: 'machines', to: '/admin/control-center/machines', total: data.machines.total, rows: Object.entries(data.machines.by_classification).map(([k, v]) => [t(`admin.controlCenter.safety.${k}`), v]) },
    { key: 'providers', to: '/admin/control-center/providers', total: data.providers.total, rows: Object.entries(data.providers.by_readiness).map(([k, v]) => [t(`status.${k}`), v]) },
    { key: 'credentials', to: '/admin/control-center/credentials', total: sum(data.credentials.by_state), rows: Object.entries(data.credentials.by_state).map(([k, v]) => [t(`status.${k}`), v]) },
    { key: 'licences', to: '/admin/control-center/licences', total: sum(data.licences.by_state), rows: Object.entries(data.licences.by_state).map(([k, v]) => [t(`status.${k}`), v]) },
    { key: 'products', to: '/admin/control-center/readiness', total: sum(data.products.by_state), rows: Object.entries(data.products.by_state).map(([k, v]) => [t(`status.${k}`), v]) },
    { key: 'deployments', to: '/admin/control-center/deployments', total: sum(data.deployments.by_state), rows: Object.entries(data.deployments.by_state).map(([k, v]) => [t(`status.${k}`), v]) },
    { key: 'sites', to: '/admin/control-center/sites', total: data.sites.datacenters, rows: [[t('admin.overview.racks'), data.sites.racks]] },
  ]

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {tiles.map((tile) => (
        <Card key={tile.key}>
          <Link to={tile.to} className="flex items-baseline justify-between gap-2" aria-label={t(`admin.overview.tiles.${tile.key}`)}>
            <span className="text-sm font-semibold">{t(`admin.overview.tiles.${tile.key}`)}</span>
            <span className="text-2xl font-semibold tabular-nums">{tile.total}</span>
          </Link>
          <dl className="mt-2 grid grid-cols-[1fr_auto] gap-x-3 gap-y-0.5 text-xs text-[var(--text-muted)]">
            {tile.rows.map(([label, value]) => (
              <div key={label} className="contents">
                <dt>{label}</dt>
                <dd className="tabular-nums text-[var(--text-secondary)]">{value}</dd>
              </div>
            ))}
          </dl>
        </Card>
      ))}
    </div>
  )
}

function sum(record: Record<string, number>): number {
  return Object.values(record).reduce((total, value) => total + value, 0)
}
