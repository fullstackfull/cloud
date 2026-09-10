import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { EmptyState } from '@/components/EmptyState'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useClaimDnsZone, useDnsZones } from '@/lib/queries'
import type { DnsZone } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * The zones this account holds: an index, since Wave 3.
 *
 * It used to be the whole of DNS — a select box in front of one zone's
 * delegation, records and import — which meant "the records of example.com"
 * was not an address anybody could send or bookmark. Claiming a zone stays
 * here, because at that moment there is no zone to open yet; everything else
 * moved to the zone's own page.
 */
export function DnsPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const { data: zones, isPending, error: readError } = useDnsZones()
  const rows = zones?.data ?? []

  const claim = useClaimDnsZone()
  const [name, setName] = useState('')

  const claimFailure = describeError(claim.error)

  const columns: Array<Column<DnsZone>> = [
    {
      key: 'zone',
      header: t('dns.zone'),
      ltr: true,
      cell: (zone) => (
        <Link
          to={`/dns/${encodeURIComponent(zone.name)}`}
          className="technical font-medium text-[var(--text-primary)] hover:underline"
        >
          {zone.name}
        </Link>
      ),
    },
    {
      key: 'state',
      header: t('dns.state'),
      cell: (zone) => (
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={zone.state} />
          {zone.needs_attention ? <Badge tone="warning">{t('domains.attention')}</Badge> : null}
        </div>
      ),
    },
    {
      key: 'records',
      header: t('dns.recordCount'),
      cell: (zone) => zone.record_count ?? '—',
    },
    {
      key: 'actions',
      header: '',
      cell: (zone) => (
        <div className="flex justify-end">
          <Link to={`/dns/${encodeURIComponent(zone.name)}`} className="text-sm underline">
            {t('resource.open')}
          </Link>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.dns')} description={t('dns.subtitle')} />

      <LoadFailure error={readError} />

      <Card>
        <form
          className="flex flex-wrap items-end gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (name.trim() === '') return

            claim.mutate({ name: name.trim() }, { onSuccess: () => { setName('') } })
          }}
        >
          <div className="min-w-[16rem] flex-1">
            <Field
              label={t('dns.domain')}
              dir="ltr"
              placeholder="example.com"
              value={name}
              onChange={(event) => { setName(event.target.value) }}
              hint={t('dns.domainHint')}
            />
          </div>

          <Button type="submit" loading={claim.isPending}>
            {t('dns.claim')}
          </Button>
        </form>

        {claimFailure === null ? null : (
          <div className="mt-3">
            <Alert tone="error" requestId={claimFailure.requestId}>
              {claimFailure.message}
            </Alert>
          </div>
        )}
      </Card>

      <div className="mt-4">
        {isPending ? (
          <Loading />
        ) : rows.length === 0 ? (
          <EmptyState>{t('dns.noZones')}</EmptyState>
        ) : (
          <Card title={t('dns.zones')} description={t('dns.zonesBody')}>
            <DataTable
              caption={t('dns.zones')}
              columns={columns}
              rows={rows}
              rowKey={(zone) => zone.id}
              empty={t('dns.noZones')}
            />
          </Card>
        )}
      </div>
    </>
  )
}
