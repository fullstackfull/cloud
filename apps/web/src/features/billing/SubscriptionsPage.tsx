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
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useCancelSubscription, useSubscriptions } from '@/lib/queries'
import type { Subscription } from '@/lib/types'

export function SubscriptionsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useSubscriptions(page)
  const cancel = useCancelSubscription()

  const columns: Array<Column<Subscription>> = [
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
              loading={cancel.isPending && cancel.variables === s.id}
              onClick={() => { cancel.mutate(s.id); }}
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
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
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
    </>
  )
}
