import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useCancelOrder, useOrder } from '@/lib/queries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

export function OrderDetailPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { id = '' } = useParams()
  const describeError = useApiErrorMessage()

  const { data: order, isPending, error } = useOrder(id)
  const cancel = useCancelOrder()

  // Cancelling an unpaid order is recoverable — nothing was charged and a new
  // order is a click away — so it takes a plain confirmation, not a typed one.
  const [cancelling, setCancelling] = useState(false)

  const displayed = describeError(cancel.error ?? error)

  if (isPending) {
    return <Loading className="py-12" />
  }

  if (order === undefined) {
    return displayed !== null ? <Alert tone="error">{displayed.message}</Alert> : null
  }

  return (
    <>
      <PageHeader
        title={t('orders.detailTitle', { number: order.number })}
        actions={<StatusBadge status={order.status} />}
      />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2" title={t('orders.items')}>
          <ul className="flex flex-col divide-y divide-[var(--border-subtle)]">
            {(order.items ?? []).map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-4 py-3">
                <div className="min-w-0">
                  <p className="truncate text-[var(--text-primary)]">{item.description}</p>
                  <p className="text-xs text-[var(--text-muted)]" dir="ltr">
                    × {item.quantity}
                  </p>
                </div>
                <MoneyText value={item.total} />
              </li>
            ))}
          </ul>
        </Card>

        <Card title={t('orders.summary')}>
          <dl className="flex flex-col gap-2 text-sm">
            <Row label={t('orders.subtotal')}>
              <MoneyText value={order.subtotal} />
            </Row>
            {order.discount.minor_units > 0 ? (
              <Row label={t('orders.discount')}>
                <MoneyText value={order.discount} />
              </Row>
            ) : null}
            <Row label={t('orders.tax')}>
              <MoneyText value={order.tax} />
            </Row>
            <div className="mt-2 border-t border-[var(--border-subtle)] pt-2">
              <Row label={t('orders.total')} strong>
                <MoneyText value={order.total} />
              </Row>
            </div>
            {order.placed_at !== null ? (
              <Row label={t('orders.placed')}>{formatDateTime(order.placed_at, locale)}</Row>
            ) : null}
          </dl>

          {order.is_cancellable ? (
            <Button
              variant="danger"
              className="mt-4 w-full"
              loading={cancel.isPending}
              onClick={() => { setCancelling(true); }}
            >
              {t('orders.cancel')}
            </Button>
          ) : null}

          <ConfirmDialog
            open={cancelling}
            title={t('orders.cancelDialog.title', { number: order.number })}
            body={<p>{t('orders.cancelDialog.body')}</p>}
            confirmLabel={t('orders.cancelDialog.confirmLabel')}
            // Two buttons reading "Cancel" would be a coin toss.
            cancelLabel={t('orders.cancelDialog.keep')}
            loading={cancel.isPending}
            onConfirm={() => { cancel.mutate(order.id, { onSettled: () => { setCancelling(false); } }); }}
            onCancel={() => { setCancelling(false); }}
          />

          {/*
            Deliberately no "mark as paid" and no success-URL handling. An order
            becomes paid when the provider tells the server so, never because a
            browser said it did.
          */}
        </Card>
      </div>
    </>
  )
}

function Row({
  label,
  children,
  strong = false,
}: {
  label: string
  children: React.ReactNode
  strong?: boolean
}) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className={strong ? 'font-medium text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'}>
        {label}
      </dt>
      <dd className={strong ? 'font-semibold text-[var(--text-primary)]' : 'text-[var(--text-primary)]'}>
        {children}
      </dd>
    </div>
  )
}
