import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { pathForResource } from '@/features/resources/resourcePaths'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useCancelOrder, useOrder } from '@/lib/queries'
import type { OrderService } from '@/lib/types'
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

        {/*
          What happens next, and where the rest of it lives.

          The links are the server's: the order carries the id of the invoice it
          produced and of the services that exist because it was paid. Nothing
          here matches an invoice to an order by amount and date, which is what
          a customer had to do before and what gets the wrong invoice paid.
        */}
        <Card className="lg:col-span-3" title={t('orders.whatHappensNext')}>
          <ol className="flex flex-col gap-3 text-sm">
            <li className="flex flex-wrap items-center gap-2">
              <span className="text-[var(--text-secondary)]">{t('orders.chainInvoice')}</span>
              {order.invoice_id != null ? (
                <Link className="underline" to={`/invoices/${order.invoice_id}`}>
                  <span className="technical" dir="ltr">
                    {order.invoice_number ?? t('orders.viewInvoice')}
                  </span>
                </Link>
              ) : (
                <span className="text-[var(--text-muted)]">{t('orders.chainInvoicePending')}</span>
              )}
            </li>

            <li className="flex flex-wrap items-center gap-2">
              <span className="text-[var(--text-secondary)]">{t('orders.chainPayment')}</span>
              <span className={order.is_paid ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'}>
                {order.is_paid ? t('orders.chainPaid') : t('orders.chainAwaitingPayment')}
              </span>
            </li>

            <li className="flex flex-col gap-1">
              <span className="text-[var(--text-secondary)]">{t('orders.chainService')}</span>
              {(order.services ?? []).length === 0 ? (
                <span className="text-[var(--text-muted)]">
                  {order.is_paid ? t('orders.chainServicePreparing') : t('orders.chainServiceAfterPayment')}
                </span>
              ) : (
                <ul className="flex flex-col gap-1">
                  {(order.services ?? []).map((service) => (
                    <li key={service.id} className="flex flex-wrap items-center gap-2">
                      {/*
                        * Straight to the thing the order produced, using the
                        * handle the API resolved — a service's own id is not
                        * the machine's, so a link built from it would 404. A
                        * service with nothing created yet says so and links
                        * nowhere; the services index is the fallback for a
                        * family the portal has no page for.
                        */}
                      <ServiceLink service={service} />
                      <StatusBadge status={service.state} />
                    </li>
                  ))}
                </ul>
              )}
            </li>
          </ol>
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

/**
 * One service an order produced, as a link to it where there is something to
 * open.
 */
function ServiceLink({ service }: { service: OrderService }) {
  const { t } = useTranslation()

  const to = service.resource === null
    ? null
    : pathForResource(service.resource.kind, service.resource.id)

  const name = (
    <span className="technical" dir="ltr">
      {service.identity ?? t('orders.chainServicePreparing')}
    </span>
  )

  return to === null ? name : (
    <Link className="underline" to={to}>
      {name}
    </Link>
  )
}
