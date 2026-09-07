import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useAdminTransactions, useIssueRefund, type AdminTransaction } from '@/lib/adminQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { useHasPermission } from './useIsOperator'

export function AdminPaymentsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const [refunding, setRefunding] = useState<AdminTransaction | null>(null)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')

  const { data, isPending, error: readError } = useAdminTransactions(page)
  const refund = useIssueRefund()
  const mayRefund = useHasPermission('payment.refund')

  const displayed = describeError(refund.error)

  const columns: Array<Column<AdminTransaction>> = [
    {
      key: 'id',
      header: t('admin.payments.transaction'),
      ltr: true,
      cell: (transaction) => (
        <div>
          <p className="technical text-xs">{transaction.id}</p>
          <p className="technical text-xs text-[var(--text-muted)]">{transaction.provider}</p>
        </div>
      ),
    },
    { key: 'kind', header: t('admin.payments.kind'), cell: (transaction) => transaction.kind },
    {
      key: 'status',
      header: t('admin.payments.status'),
      cell: (transaction) => <StatusBadge status={transaction.status} />,
    },
    {
      key: 'amount',
      header: t('admin.payments.amount'),
      cell: (transaction) => <MoneyText value={transaction.amount} />,
    },
    {
      key: 'when',
      header: t('admin.payments.when'),
      cell: (transaction) =>
        transaction.processed_at === null ? '—' : formatDateTime(transaction.processed_at, locale),
    },
    {
      key: 'actions',
      header: '',
      cell: (transaction) =>
        mayRefund && transaction.status === 'succeeded' ? (
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              setRefunding(transaction)
              // Pre-filled with the full amount, which is the common case, and
              // editable because a partial refund is the other one.
              setAmount(String(transaction.amount.minor_units))
              setReason('')
            }}
          >
            {t('admin.payments.refund')}
          </Button>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader title={t('admin.payments.title')} description={t('admin.payments.subtitle')} />

      <LoadFailure error={readError} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : (
          <>
            <DataTable
              caption={t('admin.payments.title')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(transaction) => transaction.id}
              empty={t('admin.payments.empty')}
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

      {refunding !== null ? (
        <div className="mt-4 max-w-md">
          <Card
            title={t('admin.payments.refundTitle')}
            description={t('admin.payments.refundSubtitle', { currency: refunding.amount.currency })}
          >
            <form
              className="flex flex-col gap-3"
              noValidate
              onSubmit={(event) => {
                event.preventDefault()
                refund.mutate(
                  {
                    transactionId: refunding.id,
                    amountMinor: Number(amount),
                    reason,
                  },
                  { onSuccess: () => { setRefunding(null); } },
                )
              }}
            >
              {/*
                Minor units, entered as an integer. Asking for "25.00" and
                multiplying by 100 in the browser is how a refund becomes 2499
                fils; the server takes the integer and no arithmetic happens
                here at all.
              */}
              <Field
                label={t('admin.payments.amountMinor', { currency: refunding.amount.currency })}
                type="number"
                min={1}
                max={refunding.amount.minor_units}
                dir="ltr"
                value={amount}
                onChange={(event) => { setAmount(event.target.value); }}
                hint={t('admin.payments.amountHint', { max: refunding.amount.minor_units })}
                required
                error={displayed?.fields?.['amount_minor']?.[0]}
              />

              <Field
                label={t('admin.payments.reason')}
                value={reason}
                onChange={(event) => { setReason(event.target.value); }}
                required
                error={displayed?.fields?.['reason']?.[0]}
              />

              <div className="flex gap-2">
                <Button type="submit" variant="danger" loading={refund.isPending}>
                  {t('admin.payments.issueRefund')}
                </Button>
                <Button type="button" variant="ghost" onClick={() => { setRefunding(null); }}>
                  {t('common.cancel')}
                </Button>
              </div>

              <p className="text-xs text-[var(--text-muted)]">{t('admin.payments.refundIsRecorded')}</p>
            </form>
          </Card>
        </div>
      ) : null}
    </>
  )
}
