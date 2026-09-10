import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Loading } from '@/components/Loading'
import { newIdempotencyKey } from '@/lib/api'
import { useOrderQuote, usePlaceOrder, useProduct } from '@/lib/queries'
import type { Plan, PlanPrice } from '@/lib/types'
import { cn } from '@/lib/cn'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * One product, its plans, and the checkout.
 *
 * Nothing on this page decides a price. The plan id, the quantity and the
 * billing period are all the server is sent; what it costs is resolved there
 * from the plan, and the totals shown here come back from the order it created.
 * A checkout that computes its own total is a checkout an attacker can argue
 * with.
 */
export function ProductPage() {
  const { t } = useTranslation()
  const { slug = '' } = useParams()
  const navigate = useNavigate()
  const describeError = useApiErrorMessage()

  const { data: product, isPending, error } = useProduct(slug)
  const place = usePlaceOrder()

  /*
   * What it costs, from the server, before anything is bought.
   *
   * The quote runs the same pricing engine that prices the order and issues
   * the invoice — plan price, setup fee, coupon, tax, and the renewal figure —
   * so what this screen shows is what the customer will be charged. The
   * alternative, which is what this page did before, was to show the plan's
   * recurring price and let the setup fee and the tax arrive as a surprise on
   * the invoice.
   */
  const quote = useOrderQuote()

  const [selected, setSelected] = useState<string | null>(null)
  const [period, setPeriod] = useState<string>('monthly')
  const [quantity, setQuantity] = useState(1)
  const [coupon, setCoupon] = useState('')

  /*
   * One key per basket, minted when the page loads and reused for every retry
   * of that basket. Regenerating it per click would make a double-click two
   * orders, which is the whole thing the key exists to prevent; and because the
   * server now compares a fingerprint of the request, changing the basket under
   * the same key is refused rather than silently replayed — so the key is reset
   * whenever the selection changes.
   */
  const [idempotencyKey, setIdempotencyKey] = useState(() => newIdempotencyKey())

  function chooseP(plan: Plan, billingPeriod: string) {
    setSelected(plan.id)
    setPeriod(billingPeriod)
    setIdempotencyKey(newIdempotencyKey())
  }

  const displayed = describeError(place.error ?? error)
  const quoteFailure = describeError(quote.error)

  /*
   * Re-priced whenever the basket changes, and by the server every time.
   *
   * Deliberately keyed on the whole selection: a quantity typed one digit at a
   * time asks again, which is what the endpoint's own throttle is sized for,
   * and no arithmetic happens here in between.
   */
  const basketKey = `${selected ?? ''}|${period}|${quantity}|${coupon.trim()}`

  useEffect(() => {
    if (selected === null) return

    quote.mutate({
      items: [{ plan_id: selected, quantity }],
      billing_period: period,
      ...(coupon.trim() === '' ? {} : { coupon_code: coupon.trim() }),
    })
    /*
     * The basket, and only the basket.
     *
     * Expressed as one string so a changed quantity, period or coupon is one
     * dependency rather than four — and deliberately not including the
     * mutation object, whose identity changes as the request progresses and
     * would re-price on its own answer, forever.
     */
  }, [basketKey])

  async function submit(event: React.SyntheticEvent) {
    event.preventDefault()
    if (selected === null) return

    try {
      const order = await place.mutateAsync({
        items: [{ plan_id: selected, quantity }],
        billing_period: period,
        ...(coupon.trim() === '' ? {} : { coupon_code: coupon.trim() }),
        idempotencyKey,
      })

      void navigate(`/orders/${order.id}`)
    } catch {
      // Rendered from the mutation state below.
    }
  }

  if (isPending) {
    return <Loading className="py-12" />
  }

  if (product === undefined) {
    return displayed !== null ? <Alert tone="error">{displayed.message}</Alert> : null
  }

  return (
    <>
      <PageHeader title={product.name} description={product.description ?? undefined} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-3">
        {(product.plans ?? []).map((plan) => (
          <Card key={plan.id} title={plan.name} description={plan.description ?? undefined}>
            <dl className="mb-4 flex flex-col gap-1 text-sm">
              {Object.entries(plan.resources).map(([key, value]) => (
                <div key={key} className="flex justify-between gap-3">
                  <dt className="text-[var(--text-secondary)]">
                    {t(`resources.${key}`, { defaultValue: key.replace(/_/g, ' ') })}
                  </dt>
                  <dd dir="ltr" className="tabular-nums text-[var(--text-primary)]">
                    {String(value)}
                  </dd>
                </div>
              ))}
            </dl>

            <div className="flex flex-col gap-2">
              {plan.prices.map((price: PlanPrice) => (
                <button
                  key={`${plan.id}-${price.billing_period}`}
                  type="button"
                  onClick={() => { chooseP(plan, price.billing_period); }}
                  aria-pressed={selected === plan.id && period === price.billing_period}
                  className={cn(
                    'flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm transition-colors',
                    selected === plan.id && period === price.billing_period
                      ? 'border-brand-600 bg-brand-600/10'
                      : 'border-[var(--border-subtle)] hover:border-[var(--border-strong)]',
                  )}
                >
                  <span>{t(`billingPeriod.${price.billing_period}`, { defaultValue: price.billing_period })}</span>
                  <MoneyText value={price.recurring} />
                </button>
              ))}

              {plan.prices.length === 0 ? (
                <p className="text-xs text-[var(--text-muted)]">{t('catalogue.noPriceInCurrency')}</p>
              ) : null}
            </div>
          </Card>
        ))}
      </div>

      {selected !== null ? (
        <div className="mt-6 max-w-md">
          <Card title={t('catalogue.checkoutTitle')}>
            <form onSubmit={(event) => void submit(event)} className="flex flex-col gap-4" noValidate>
              <Field
                label={t('catalogue.quantity')}
                type="number"
                min={1}
                max={20}
                value={quantity}
                dir="ltr"
                onChange={(event) => { setQuantity(Math.max(1, Number(event.target.value))); }}
              />

              <Field
                label={t('catalogue.couponCode')}
                value={coupon}
                dir="ltr"
                onChange={(event) => { setCoupon(event.target.value); }}
                hint={t('catalogue.couponHint')}
              />

              {/*
                The price, itemised, before the customer commits. Every figure
                is the server's and none of them is added up here.
              */}
              {quote.isPending ? (
                <Loading />
              ) : quote.data !== undefined ? (
                <dl className="flex flex-col gap-2 border-t border-[var(--border-subtle)] pt-4 text-sm">
                  {quote.data.lines.map((line) => (
                    <div key={line.plan_id} className="flex justify-between gap-4">
                      <dt className="text-[var(--text-secondary)]">
                        {line.description}
                        <span dir="ltr" className="text-xs text-[var(--text-muted)]">
                          {' '}
                          × {line.quantity}
                        </span>
                      </dt>
                      <dd>
                        <MoneyText value={line.gross} />
                      </dd>
                    </div>
                  ))}

                  {quote.data.setup.minor_units > 0 ? (
                    <div className="flex justify-between gap-4">
                      <dt className="text-[var(--text-secondary)]">{t('catalogue.setupFee')}</dt>
                      <dd>
                        <MoneyText value={quote.data.setup} />
                      </dd>
                    </div>
                  ) : null}

                  {quote.data.discount.minor_units > 0 ? (
                    <div className="flex justify-between gap-4">
                      <dt className="text-[var(--text-secondary)]">
                        {quote.data.coupon_code === null
                          ? t('catalogue.discount')
                          : t('catalogue.discountWithCode', { code: quote.data.coupon_code })}
                      </dt>
                      <dd>
                        <MoneyText value={quote.data.discount} />
                      </dd>
                    </div>
                  ) : null}

                  <div className="flex justify-between gap-4">
                    <dt className="text-[var(--text-secondary)]">
                      {quote.data.tax_name === null
                        ? t('catalogue.tax')
                        : t('catalogue.taxNamed', { name: quote.data.tax_name })}
                    </dt>
                    <dd>
                      <MoneyText value={quote.data.tax} />
                    </dd>
                  </div>

                  <div className="flex justify-between gap-4 border-t border-[var(--border-subtle)] pt-2">
                    <dt className="font-medium text-[var(--text-primary)]">
                      {t('catalogue.dueNow')}
                    </dt>
                    <dd className="font-semibold">
                      <MoneyText value={quote.data.total} />
                    </dd>
                  </div>

                  {/*
                    What it costs next time, which is a different question:
                    the setup fee is not charged again and a coupon that
                    discounted this purchase does not discount every future
                    one. Both are said out loud.
                  */}
                  <div className="flex justify-between gap-4">
                    <dt className="text-[var(--text-secondary)]">
                      {t('catalogue.thenPerPeriod', {
                        period: t(`billingPeriod.${quote.data.renewal.billing_period}`, {
                          defaultValue: quote.data.renewal.billing_period,
                        }),
                      })}
                    </dt>
                    <dd>
                      <MoneyText value={quote.data.renewal.total} />
                    </dd>
                  </div>

                  <p className="text-xs text-[var(--text-muted)]">{t('catalogue.renewalNote')}</p>
                </dl>
              ) : quoteFailure !== null ? (
                <Alert tone="error" requestId={quoteFailure.requestId}>
                  {quoteFailure.message}
                </Alert>
              ) : null}

              <Button type="submit" size="lg" loading={place.isPending}>
                {t('catalogue.placeOrder')}
              </Button>

              <p className="text-xs text-[var(--text-muted)]">{t('catalogue.priceIsServerSide')}</p>
              <p className="text-xs text-[var(--text-muted)]">{t('catalogue.afterPayment')}</p>
            </form>
          </Card>
        </div>
      ) : null}
    </>
  )
}
