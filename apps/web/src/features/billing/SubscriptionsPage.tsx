import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useCancelSubscription, useSubscriptions } from '@/lib/queries'
import type { Subscription } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * A customer's subscriptions, and the two ways of ending one.
 *
 * Cancelling used to be a single click with no explanation. It is the act that
 * decides when somebody's data is destroyed, and a customer is entitled to
 * know both dates — the day the service stops and the day what is on it goes —
 * before they confirm rather than in an email afterwards.
 *
 * Ending immediately is offered inside the same dialogue rather than as a
 * second button, because the choice is between two forms of one decision. It
 * takes the subscription's own id typed back, which the server checks: it is
 * not a lookup, it is evidence that a person read the sentence about the
 * remainder of the period not being refunded.
 */
/**
 * The one thing a customer needs to tell two subscriptions apart: the plan,
 * the product it belongs to, and the machine or domain it runs.
 *
 * Every part comes from the server. `identity` is null while a service is
 * still being created, and the screen says that rather than showing a
 * placeholder that looks like a hostname.
 */
function SubscriptionIdentity({ subscription }: { subscription: Subscription }) {
  const { t } = useTranslation()

  const services = subscription.services ?? []
  const planName = subscription.plan?.name ?? null
  const productName = subscription.product?.name ?? null

  return (
    <div className="flex flex-col gap-0.5">
      <span className="font-medium text-[var(--text-primary)]">
        {planName ?? t('subscriptions.unnamedPlan')}
      </span>

      {productName !== null ? (
        <span className="text-xs text-[var(--text-muted)]">{productName}</span>
      ) : null}

      {services.length === 0 ? (
        <span className="text-xs text-[var(--text-muted)]">{t('subscriptions.noService')}</span>
      ) : (
        services.map((service) => (
          <span key={service.id} className="text-xs text-[var(--text-secondary)]">
            {service.identity === null ? (
              t('subscriptions.serviceBeingCreated')
            ) : (
              <span className="technical" dir="ltr">
                {service.identity}
              </span>
            )}
          </span>
        ))
      )}
    </div>
  )
}

export function SubscriptionsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useSubscriptions(page)
  const cancel = useCancelSubscription()

  const [ending, setEnding] = useState<Subscription | null>(null)
  const [immediately, setImmediately] = useState(false)

  const cancelFailure = describeError(cancel.error)

  function close() {
    setEnding(null)
    setImmediately(false)
    cancel.reset()
  }

  const columns: Array<Column<Subscription>> = [
    {
      /*
       * What the agreement is for, first, because it is what a customer looks
       * for. Two monthly subscriptions at the same price used to be two
       * identical rows, and cancelling one of them was a guess with a
       * production machine on the other side.
       */
      key: 'what',
      header: t('subscriptions.what'),
      cell: (s) => <SubscriptionIdentity subscription={s} />,
    },
    { key: 'status', header: t('subscriptions.status'), cell: (s) => <StatusBadge status={s.status} /> },
    {
      key: 'amount',
      header: t('subscriptions.recurring'),
      cell: (s) => (
        <span>
          <MoneyText value={s.recurring_amount} />{' '}
          <span className="text-xs text-[var(--text-muted)]">
            {t(`billingPeriod.${s.billing_period}`, { defaultValue: s.billing_period })}
          </span>
        </span>
      ),
    },
    {
      key: 'renews',
      header: t('subscriptions.renews'),
      cell: (s) =>
        s.is_scheduled_to_cancel
          ? t('subscriptions.endsOn', {
              date: s.current_period_end === null ? '—' : formatDate(s.current_period_end, locale),
            })
          : s.next_invoice_at === null
            ? '—'
            : formatDate(s.next_invoice_at, locale),
    },
    {
      key: 'actions',
      header: '',
      cell: (s) => (
        <div className="flex flex-wrap items-center gap-2">
          {/*
            * Offered on a subscription that is ending too. A customer who has
            * scheduled a cancellation and then decides to stay smaller should
            * find the option where it always was, rather than having to
            * un-cancel first.
            */}
          <Link
            to={`/subscriptions/${s.id}/plan`}
            className="inline-flex items-center rounded border border-[var(--border)] px-2 py-1 text-xs text-[var(--text-primary)] hover:bg-[var(--surface-sunken)]"
          >
            {t('subscriptions.changePlan')}
          </Link>

          {s.is_scheduled_to_cancel ? null : (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => { setEnding(s); }}
            >
              {t('subscriptions.cancel')}
            </Button>
          )}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.subscriptions')} description={t('subscriptions.subtitle')} />

      <LoadFailure error={readError} />

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.subscriptions')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(s) => s.id}
              empty={t('subscriptions.empty')}
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

      {/*
        * Mounted only while open, so a closed dialogue's confirm button is not
        * a second "cancel subscription" control sitting in the document.
        */}
      {ending === null ? null : (
        <ConfirmDialog
          open
          title={t('subscriptions.cancelTitle')}
          body={
            <div className="flex flex-col gap-2">
              {/*
                * Which subscription this is, spelled out inside the dialogue.
                * A confirmation that says only "cancel this subscription" is
                * a confirmation of nothing in particular.
                */}
              <div className="rounded-lg border border-[var(--border-subtle)] p-3">
                <SubscriptionIdentity subscription={ending} />
              </div>

              <p>
                {immediately
                  ? t('subscriptions.cancelNowWarning')
                  : t('subscriptions.cancelWarning', {
                      date:
                        ending.current_period_end === null
                          ? '—'
                          : formatDate(ending.current_period_end, locale),
                    })}
              </p>

              {/* The second date, which is the one customers ring up about. */}
              <p>
                {t('subscriptions.dataWarning', { days: ending.data_retention_days ?? 0 })}
              </p>

              <label className="mt-1 flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={immediately}
                  onChange={(event) => { setImmediately(event.target.checked) }}
                />
                {t('subscriptions.endNowOption', {
                  date:
                    ending.current_period_end === null
                      ? '—'
                      : formatDate(ending.current_period_end, locale),
                })}
              </label>

              {immediately ? (
                <p className="technical text-xs break-all select-all" dir="ltr">
                  {ending.id}
                </p>
              ) : null}
            </div>
          }
          /*
           * Spread rather than passed as undefined: under
           * exactOptionalPropertyTypes an optional prop must be absent, and
           * the distinction is the whole guard here — a ConfirmDialog with no
           * requiredPhrase confirms on one click.
           */
          {...(immediately
            ? {
                requiredPhrase: ending.id,
                requiredPhraseLabel: t('subscriptions.cancelConfirmLabel'),
              }
            : {})}
          /*
           * Never the word "Cancel". The dialogue's own dismiss button says
           * that, and in a dialogue it means "do not do this" — two buttons
           * reading Cancel, one of which ends the customer's service, is the
           * kind of thing somebody clicks once and remembers for years.
           */
          confirmLabel={
            immediately ? t('subscriptions.endNowConfirm') : t('subscriptions.confirmEnd')
          }
          loading={cancel.isPending}
          {...(cancelFailure === null ? {} : { error: cancelFailure.message })}
          onCancel={close}
          onConfirm={(confirmation) => {
            cancel.mutate(
              { id: ending.id, immediately, ...(immediately ? { confirmation } : {}) },
              { onSuccess: close },
            )
          }}
        />
      )}

      {cancelFailure === null || ending !== null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={cancelFailure.requestId}>
            {cancelFailure.message}
          </Alert>
        </div>
      )}
    </>
  )
}
