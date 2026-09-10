import { useTranslation } from 'react-i18next'

import { useActiveLocale } from '@/i18n/useActiveLocale'
import { Card } from '@/components/Card'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { CapabilityBadge } from '@/features/controlCenter/CapabilityBadge'
import { useProviders, useServers } from '@/lib/controlCenterQueries'
import { formatDateTime } from '@/lib/format'

/**
 * What the platform has actually found out, laid out so the gaps are the
 * first thing seen.
 *
 * Two matrices. Providers by category against the capabilities that category
 * is asked about: a cell is what a real connection test observed, and a
 * provider with no cells at all is one nobody has asked. Machines against
 * their last look. Nothing here is inferred from a vendor's name, and
 * nothing here can be edited — this is the read-only side of the control
 * centre, and its job is to make "nobody has checked" visible.
 */
export function DiscoveryPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const providers = useProviders(1, {})
  const servers = useServers(1)

  return (
    <>
      <PageHeader title={t('admin.discovery.title')} description={t('admin.discovery.subtitle')} />

      <Card>
        <h2 className="mb-3 text-base font-semibold">{t('admin.discovery.providersHeading')}</h2>
        {providers.isPending ? (
          <Loading />
        ) : providers.error ? (
          <LoadFailure error={providers.error} />
        ) : providers.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.discovery.noProviders')}</p>
        ) : (
          <ul className="flex flex-col gap-4">
            {providers.data.data.map((provider) => (
              <li key={provider.id} className="rounded-lg border border-[var(--border-subtle)] p-3" aria-label={provider.name}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p>
                    <span className="technical font-medium">{provider.name}</span>{' '}
                    <span className="text-xs text-[var(--text-muted)]">
                      {t(`admin.providers.categories.${provider.category}`)} · {t(`admin.controlCenter.environments.${provider.environment}`)}
                    </span>
                  </p>
                  <span className="flex items-center gap-2 text-xs text-[var(--text-muted)]">
                    <StatusBadge status={provider.connection.state} />
                    {provider.connection.last_discovery_at === null
                      ? t('admin.discovery.neverAsked')
                      : t('admin.discovery.lastAsked', { when: formatDateTime(provider.connection.last_discovery_at, locale) })}
                  </span>
                </div>
                {provider.capabilities === undefined || provider.capabilities.length === 0 ? (
                  <p className="mt-2 text-sm text-[var(--text-muted)]">{t('admin.discovery.nothingObserved')}</p>
                ) : (
                  <ul className="mt-2 flex flex-wrap gap-2">
                    {provider.capabilities.map((capability) => (
                      <li key={capability.capability}>
                        <CapabilityBadge capability={capability.capability} state={capability.capability_state} />
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <h2 className="mb-3 text-base font-semibold">{t('admin.discovery.machinesHeading')}</h2>
        {servers.isPending ? (
          <Loading />
        ) : servers.error ? (
          <LoadFailure error={servers.error} />
        ) : servers.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.discovery.noMachines')}</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {servers.data.data.map((server) => (
              <li key={server.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[var(--border-subtle)] p-3" aria-label={server.name}>
                <p>
                  <span className="technical font-medium">{server.name}</span>{' '}
                  <span className="text-xs text-[var(--text-muted)]">{t(`admin.controlCenter.safety.${server.safety.classification}`)}</span>
                </p>
                <span className="flex items-center gap-2 text-xs text-[var(--text-muted)]">
                  <StatusBadge status={server.connection.state} />
                  {server.last_discovery_at === null
                    ? t('admin.discovery.neverLookedAt')
                    : t('admin.discovery.lastLookedAt', { when: formatDateTime(server.last_discovery_at, locale) })}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}
