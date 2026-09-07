import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useServices } from '@/lib/queries'
import type { Service } from '@/lib/types'

export function ServicesPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const { data, isPending } = useServices(page)

  const columns: Array<Column<Service>> = [
    {
      key: 'label',
      header: t('services.label'),
      cell: (service) => (
        <span className="font-medium text-[var(--text-primary)]">
          {service.label ?? t('services.unnamed')}
        </span>
      ),
    },
    {
      key: 'kind',
      header: t('services.kind'),
      cell: (service) => t(`catalogue.kinds.${service.kind}`, { defaultValue: service.kind }),
    },
    { key: 'state', header: t('services.state'), cell: (service) => <StatusBadge status={service.state} /> },
    {
      key: 'since',
      header: t('services.activated'),
      cell: (service) =>
        service.activated_at === null ? '—' : formatDate(service.activated_at, locale),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.services')} description={t('services.subtitle')} />

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : (
          <>
            <DataTable
              caption={t('nav.services')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(service) => service.id}
              empty={t('services.empty')}
            />
            <Paginator
              page={data?.meta.page ?? 1}
              lastPage={data?.meta.last_page ?? 1}
              total={data?.meta.total ?? 0}
              onChange={setPage}
            />
          </>
        )}
      </Card>
    </>
  )
}
