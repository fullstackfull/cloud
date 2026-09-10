import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Card } from '@/components/Card'
import { StatusBadge } from '@/components/StatusBadge'
import type { DnsZone } from '@/lib/types'

/**
 * What has to happen at the registrar, said before anything else.
 *
 * This is the only part of the screen a customer needs on the day they claim a
 * domain, and the part they come back for when their site does not resolve.
 */
export function ZoneDelegation({ zone }: { zone: DnsZone }) {
  const { t } = useTranslation()

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="technical text-base font-medium" dir="ltr">
          {zone.name}
        </h2>
        <StatusBadge status={zone.state} />
      </div>

      <p className="mt-2 text-sm text-[var(--text-muted)]">{t('dns.delegationExplainer')}</p>

      {zone.nameservers.length === 0 ? (
        <p className="mt-3 text-sm text-[var(--text-muted)]">{t('dns.noNameservers')}</p>
      ) : (
        <ul className="mt-3 flex flex-col gap-1">
          {zone.nameservers.map((host) => (
            <li key={host} className="technical text-sm select-all" dir="ltr">
              {host}
            </li>
          ))}
        </ul>
      )}

      {zone.failure_reason === null ? null : (
        <div className="mt-3">
          <Alert tone="error">{zone.failure_reason}</Alert>
        </div>
      )}

      {zone.needs_attention ? (
        <div className="mt-3">
          {/*
            * Not an error and deliberately not styled as one. The zone may be
            * perfectly fine; what the platform is saying is that it does not
            * know, and telling somebody to try again would be the one thing
            * that could make it worse.
            */}
          <Alert tone="warning">{t('dns.needsAttention')}</Alert>
        </div>
      ) : null}
    </Card>
  )
}
