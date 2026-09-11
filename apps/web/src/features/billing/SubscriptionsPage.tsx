import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useSubscriptions } from '@/lib/queries'
import type { Subscription } from '@/lib/types'
import { safeLabel } from '@/lib/safeLabel'

import { CancelSubscriptionDialog, SubscriptionIdentity } from './CancelSubscriptionDialog'

/**
 * A customer's subscriptions: what each one pays for, and the way out.
 *
 * The row names the plan, the product and the service, because two monthly
 * subscriptions at the same price were two identical rows and cancelling one
 * of them was a guess with a production machine on the other side. Since
 * Wave 3 the service is a link: the subscription is the commercial half of a
 * thing that also has a home of its own.
 *
 * Ending one is CancelSubscriptionDialog's job, and the resource pages open
 * the same dialogue.
 */
export function SubscriptionsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useSubscriptions(page)

  const [ending, setEnding] = useState<Subscription | null>(null)

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
            {safeLabel('billingPeriod', s.billing_period)}
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
        * a second "cancel subscription" control sitting in the document. The
        * dialogue itself lives beside the resource pages that also offer it —
        * one implementation of the act that destroys somebody's data.
        */}
      {ending === null ? null : (
        <CancelSubscriptionDialog subscription={ending} onClose={() => { setEnding(null); }} />
      )}

    </>
  )
}
