import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useOrders } from '@/lib/queries'
import type { Order } from '@/lib/types'

export function OrdersPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const { data, isPending } = useOrders(page)

  const columns: Array<Column<Order>> = [
    {
      key: 'number',
      header: t('orders.number'),
      ltr: true,
      cell: (order) => (
        <Link to={`/orders/${order.id}`} className="technical font-medium text-brand-600 hover:underline">
          {order.number}
        </Link>
      ),
    },
    { key: 'status', header: t('orders.status'), cell: (order) => <StatusBadge status={order.status} /> },
    { key: 'total', header: t('orders.total'), cell: (order) => <MoneyText value={order.total} /> },
    {
      key: 'placed',
      header: t('orders.placed'),
      cell: (order) => (order.placed_at === null ? '—' : formatDate(order.placed_at, locale)),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.orders')} description={t('orders.subtitle')} />

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : (
          <>
            <DataTable
              caption={t('nav.orders')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(order) => order.id}
              empty={t('orders.empty')}
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
