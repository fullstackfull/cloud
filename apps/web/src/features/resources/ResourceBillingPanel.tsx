import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { FactList, type Fact } from '@/components/FactList'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { MoneyText } from '@/components/MoneyText'
import { StatusBadge } from '@/components/StatusBadge'
import { CancelSubscriptionDialog } from '@/features/billing/CancelSubscriptionDialog'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useOrder, useService, useSubscription } from '@/lib/queries'

/**
 * What this resource costs, and the papers behind it.
 *
 * Everything here is a relation the server published — the service carries the
 * subscription and the order it came from, the order carries its invoice —
 * rather than a match on amount, date or product kind. The audit's finding was
 * that a customer had to infer the whole chain; guessing it in the client
 * would have been the same defect with better manners.
 *
 * No money is computed. The amounts are the server's own, rendered by
 * MoneyText, and the plan change and the cancellation are the same routes and
 * the same dialogue the subscriptions screen uses.
 *
 * Three requests, and only on the tab that needs them: the service, then its
 * subscription and its order. A resource page that fetched all of this up
 * front would spend them on every visitor who only wanted the hostname.
 */
export function ResourceBillingPanel({ serviceId }: { serviceId: string | null }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [ending, setEnding] = useState(false)

  const { data: service, isPending: servicePending, error: serviceError } = useService(serviceId)
  const subscriptionId = service?.subscription_id ?? null
  const orderId = service?.order_id ?? null

  const {
    data: subscription,
    isPending: subscriptionPending,
    error: subscriptionError,
  } = useSubscription(subscriptionId)

  const { data: order } = useOrder(orderId)

  if (serviceId === null) {
    return (
      <Card title={t('resource.billing')}>
        <p className="text-sm text-[var(--text-muted)]">{t('resource.noBillingRelation')}</p>
      </Card>
    )
  }

  if (servicePending || (subscriptionId !== null && subscriptionPending)) {
    return (
      <Card title={t('resource.billing')}>
        <Loading />
      </Card>
    )
  }

  const facts: Fact[] = [
    {
      label: t('subscriptions.plan'),
      value: subscription?.plan?.name ?? null,
    },
    {
      label: t('subscriptions.recurring'),
      value:
        subscription === undefined ? null : (
          <span>
            <MoneyText value={subscription.recurring_amount} />{' '}
            <span className="text-xs text-[var(--text-muted)]">
              {t(`billingPeriod.${subscription.billing_period}`, {
                defaultValue: subscription.billing_period,
              })}
            </span>
          </span>
        ),
    },
    {
      label: t('subscriptions.status'),
      value: subscription === undefined ? null : <StatusBadge status={subscription.status} />,
    },
    {
      /*
       * One column, two meanings, and the label says which: a subscription
       * that is ending has an end date rather than a renewal date, and
       * printing the end date under "Renews" is how a customer comes to
       * believe a cancelled service will come back.
       */
      label:
        subscription?.is_scheduled_to_cancel === true
          ? t('subscriptions.ends')
          : t('subscriptions.renews'),
      value:
        subscription === undefined
          ? null
          : subscription.is_scheduled_to_cancel
            ? subscription.current_period_end === null
              ? null
              : formatDate(subscription.current_period_end, locale)
            : subscription.next_invoice_at === null
              ? null
              : formatDate(subscription.next_invoice_at, locale),
    },
    {
      label: t('orders.order'),
      value:
        order === undefined ? null : (
          <Link to={`/orders/${order.id}`} className="underline">
            <span className="technical" dir="ltr">
              {order.number}
            </span>
          </Link>
        ),
    },
    {
      label: t('invoices.invoice'),
      value:
        order?.invoice_id === null || order?.invoice_id === undefined ? null : (
          <Link to={`/invoices/${order.invoice_id}`} className="underline">
            <span className="technical" dir="ltr">
              {order.invoice_number ?? order.invoice_id}
            </span>
          </Link>
        ),
    },
  ]

  return (
    <>
      <Card
        title={t('resource.billing')}
        description={t('resource.billingBody')}
        actions={
          subscription === undefined ? null : (
            <div className="flex flex-wrap items-center gap-2">
              {/*
                Offered only where the platform actually allows it. A plan
                change on a subscription that is ending is the platform's
                decision to publish, not the portal's to guess, so the link
                goes to the screen that quotes it and refuses with reasons.
              */}
              <Link
                to={`/subscriptions/${subscription.id}/plan`}
                className="inline-flex items-center rounded border border-[var(--border)] px-2 py-1 text-xs text-[var(--text-primary)] hover:bg-[var(--surface-sunken)]"
              >
                {t('subscriptions.changePlan')}
              </Link>

              {subscription.is_scheduled_to_cancel ? null : (
                <Button variant="ghost" size="sm" onClick={() => { setEnding(true); }}>
                  {t('subscriptions.cancel')}
                </Button>
              )}
            </div>
          )
        }
      >
        <LoadFailure error={serviceError} />
        <LoadFailure error={subscriptionError} />

        {subscription === undefined && subscriptionId === null ? (
          <p className="text-sm text-[var(--text-muted)]">{t('resource.noSubscription')}</p>
        ) : (
          <FactList facts={facts} />
        )}
      </Card>

      {ending && subscription !== undefined ? (
        <CancelSubscriptionDialog
          subscription={subscription}
          onClose={() => { setEnding(false); }}
        />
      ) : null}
    </>
  )
}
